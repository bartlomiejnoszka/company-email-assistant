<?php

declare(strict_types=1);

namespace App\Tests;

use App\Reply\Application\Port\DraftAi;
use App\Interface\Console\GenerateDraftsCommand;
use App\MailProcessing\Application\Port\Mailbox;
use App\MailProcessing\Infrastructure\ReplyComposer;
use App\MailProcessing\Domain\SourceMessage;
use App\MailProcessing\Infrastructure\TextExtractor;
use App\Configuration\Domain\CompanyConfig;
use App\Reply\Domain\FactSelector;
use App\Reply\Domain\ProposalValidator;
use App\Reply\Domain\ReplyRenderer;
use App\Reply\Domain\Selection;
use App\MailProcessing\Infrastructure\StateStore;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\{EntityManager, ORMSetup};
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Yaml\Yaml;

final class Support
{
    public const TEST_RECIPIENT = 'test+delivery@example.test';
    public static function config(): array
    {
        return [
            'office' => ['name' => 'Test Office', 'sender_address' => 'office@example.test', 'reply_language' => 'pl', 'signature' => 'Test Office\nTest signature', 'contact' => [], 'timezone' => 'Europe/Warsaw'],
            'knowledge' => ['facts' => ['documents' => ['text' => 'Zatwierdzona odpowiedź testowa.', 'topics' => ['documents']]]],
            'pricing' => ['prices' => []],
            'reply_rules' => ['tone' => 'Formalny', 'greeting' => 'Dzień dobry,', 'closing' => 'Z poważaniem,', 'topics' => ['documents' => ['terms' => ['dokumenty']]], 'clarifications' => ['detail' => ['text' => 'Jakiego dokumentu dotyczy pytanie?', 'topics' => ['documents']]], 'excluded_terms' => ['wykluczone'], 'manual_terms' => ['spór']],
        ];
    }
    public static function writeConfig(string $dir, ?array $data = null): FixtureConfigProvider
    {
        file_put_contents($dir.'/fixture.json', json_encode($data ?? self::config(), JSON_THROW_ON_ERROR));
        return new FixtureConfigProvider($dir);
    }
    public static function em(string $path): EntityManager
    {
        $config = ORMSetup::createConfiguration(true);
        $config->setMetadataDriverImpl(new \Doctrine\ORM\Mapping\Driver\SimplifiedXmlDriver([dirname(__DIR__).'/src/MailProcessing/Infrastructure/Mapping' => 'App\\MailProcessing\\Domain']));
        $config->enableNativeLazyObjects(true);
        $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]), $config);
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        return $em;
    }
    public static function mime(int $uid = 1, string $body = 'Proszę o dokumenty.'): string
    {
        return "From: Client <client@example.test>\r\nTo: office@example.test\r\nMessage-ID: <source-{$uid}@example.test>\r\nSubject: dokumenty\r\nDate: Sun, 20 Sep 2026 12:00:00 +0200\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n".$body;
    }
    public static function source(string $mime, int $uid = 1): SourceMessage
    {
        return new SourceMessage($uid, explode("\r\n\r\n", $mime, 2)[0], strlen($mime));
    }
    public static function proposal(array $changes = []): array
    {
        return array_replace(['outcome' => 'reply', 'blocks' => ['fact:documents'], 'used_fact_ids' => ['documents'], 'used_price_ids' => [], 'clarification_ids' => [], 'reason' => 'supported'], $changes);
    }
    public static function command(\App\Configuration\Application\Port\ConfigProvider $loader, FakeMailbox $mail, FakeAi $ai, EntityManager $em, ?\App\MailProcessing\Application\Port\ReplySender $sender = null, bool $autoSend = false): GenerateDraftsCommand
    {
        return new GenerateDraftsCommand(new \App\MailProcessing\Application\ProcessMailbox($loader, $mail, $ai, new StateStore($em), new TextExtractor(), new \App\Reply\Application\ResponseGenerator($ai, new FactSelector(), new ProposalValidator(), new ReplyRenderer()), new ReplyComposer(), '2026-09-01T00:00:00+02:00', new \App\MailProcessing\Infrastructure\FlockRunLock(new StateStore($em)), $sender, new \App\MailProcessing\Domain\TestAutoReply($autoSend, [Support::TEST_RECIPIENT])));
    }
}
final class FakeMailbox implements Mailbox
{
    public int $connections = 0;
    public int $bodyCalls = 0;
    public int $appends = 0;
    public int $flags = 0;
    public string $validity = '1';
    public array $messages = [];
    public array $drafts = [];
    public bool $crashBeforeAppend = false;
    public bool $crashAfterAppend = false;
    public bool $failFlag = false;
    public bool $failSearch = false;
    public bool $failBody = false;
    public function connect(): void
    {
        ++$this->connections;
    }
    public function identity(): string
    {
        return hash('sha256', 'test-mailbox');
    }
    public function uidValidity(): string
    {
        return $this->validity;
    }
    public function sources(\DateTimeImmutable $since): iterable
    {
        foreach ($this->messages as $uid => $mime) {
            yield Support::source($mime, $uid);
        }
    }
    public function body(SourceMessage $source): string
    {
        ++$this->bodyCalls;
        if ($this->failBody) {
            throw new \RuntimeException('SECRET');
        } return $this->messages[$source->uid];
    }
    public function append(string $mime): ?string
    {
        ++$this->appends;
        if ($this->crashBeforeAppend) {
            throw new \RuntimeException('SECRET');
        }
        $this->drafts[] = $mime;
        if ($this->crashAfterAppend) {
            throw new \RuntimeException('SECRET');
        }
        return (string) count($this->drafts);
    }
    public function findDraft(string $messageId): array
    {
        if ($this->failSearch) {
            throw new \RuntimeException('SECRET');
        }
        $found = [];
        foreach ($this->drafts as $i => $draft) {
            if (Support::source($draft)->header('Message-ID') === '<'.$messageId.'>') {
                $found[] = (string) ($i + 1);
            }
        }
        return $found;
    }
    public function flag(int $uid): void
    {
        ++$this->flags;
        if ($this->failFlag) {
            throw new \RuntimeException('SECRET');
        }
    }
    public function disconnect(): void
    {
    }
}
final class FakeAi implements DraftAi
{
    public int $calls = 0;
    public bool $fail = false;
    public ?array $proposal = null;
    public int $matchCalls = 0;
    public bool $failMatch = false;
    public array $matchResult = ['outcome' => 'matched', 'confidence' => 'high', 'topic_ids' => ['documents']];
    public int $rewriteCalls = 0;
    public ?array $rewriteResult = null;
    public bool $failRewrite = false;
    public function preflight(): void
    {
    }
    public function matchTopics(string $text, CompanyConfig $config): array
    {
        ++$this->matchCalls;
        if ($this->failMatch) {
            throw new \RuntimeException('SECRET classification failure');
        }
        return $this->matchResult;
    }
    public function rewrite(string $text, array $editable, CompanyConfig $config): array
    {
        ++$this->rewriteCalls;
        if ($this->failRewrite) {
            throw new \RuntimeException('SECRET rewrite failure');
        }
        return $this->rewriteResult ?? ['outcome' => 'edited', 'edits' => array_map(static fn ($id, $block) => ['id' => $id, 'text' => $block['text']], array_keys($editable), array_values($editable))];
    }
    public function propose(string $text, Selection $selection, CompanyConfig $config): array
    {
        ++$this->calls;
        if ($this->fail) {
            throw new \RuntimeException('SECRET email content');
        }
        return $this->proposal ?? Support::proposal();
    }
}

/** Unit tests inject normalized data; only PolishConfigTest exercises YAML parsing. */
final class FixtureConfigProvider implements \App\Configuration\Application\Port\ConfigProvider
{
    public function __construct(private readonly string $directory)
    {
    }
    public function load(): CompanyConfig
    {
        return (new \App\Configuration\Domain\ConfigValidator())->validate(json_decode(file_get_contents($this->directory.'/fixture.json'), true, flags: JSON_THROW_ON_ERROR));
    }
}
