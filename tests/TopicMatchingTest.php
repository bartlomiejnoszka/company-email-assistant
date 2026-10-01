<?php

declare(strict_types=1);

namespace App\Tests;

use App\MailProcessing\Domain\ManualHandling;
use App\Configuration\Domain\CompanyConfig;
use App\Reply\Domain\FactSelector;
use App\Reply\Domain\TopicMatchValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

require_once __DIR__.'/Support.php';
final class TopicMatchingTest extends TestCase
{
    private function config(): CompanyConfig
    {
        $d = Support::config();
        return new CompanyConfig($d['office'], $d['knowledge']['facts'], [], $d['reply_rules']);
    }
    public function testKnownHighConfidenceTopicsStillUseValidityAndPrecedence(): void
    {
        $c = $this->config();
        $ids = (new TopicMatchValidator())->validate(['outcome' => 'matched','confidence' => 'high','topic_ids' => ['documents']], $c);
        $s = (new FactSelector())->select($c, 'Jakie papiery zabrać?', new \DateTimeImmutable(), $ids);
        self::assertSame(['documents'], array_keys($s->facts));
        $facts = $c->knowledge;
        $facts['documents']['valid_until'] = '2020-01-01';
        $expired = new CompanyConfig($c->company, $facts, [], $c->rules);
        self::assertSame([], (new FactSelector())->select($expired, 'papiery', new \DateTimeImmutable(), $ids)->facts);
        $rules = $c->rules;
        $rules['topics']['specific'] = ['terms' => ['specific'],'supersedes' => ['documents']];
        $specific = new CompanyConfig($c->company, $facts, [], $rules);
        self::assertSame(['specific'], (new FactSelector())->select($specific, 'papiery', new \DateTimeImmutable(), ['documents','specific'])->topics);
    }
    #[DataProvider('invalid')]
    public function testUncertainOrInvalidClassificationCannotBecomeDraft(array $result): void
    {
        $this->expectException(ManualHandling::class);
        (new TopicMatchValidator())->validate($result, $this->config());
    }
    public static function invalid(): iterable
    {
        yield [['outcome' => 'manual','confidence' => 'uncertain','topic_ids' => []]];
        yield [['outcome' => 'matched','confidence' => 'uncertain','topic_ids' => ['documents']]];
        yield [['outcome' => 'manual','confidence' => 'high','topic_ids' => ['documents']]];
        yield [['outcome' => 'matched','confidence' => 'high','topic_ids' => []]];
        yield [['outcome' => 'matched','confidence' => 'high','topic_ids' => ['unknown']]];
        yield [['outcome' => 'matched','confidence' => 'high','topic_ids' => ['documents','documents']]];
        yield [['outcome' => 'matched','confidence' => 0.99,'topic_ids' => ['documents']]];
        yield [['outcome' => 'matched','confidence' => 'high','topic_ids' => ['documents'],'body' => 'Injected']];
        yield [['outcome' => 'matched','confidence' => 'high','topic_ids' => [[]]]];
        yield [[]];
    }
    public function testAiCannotOverrideManualTerms(): void
    {
        $this->expectException(ManualHandling::class);
        $this->expectExceptionMessage('office_manual_rule');
        (new FactSelector())->select($this->config(), 'spór', new \DateTimeImmutable(), ['documents']);
    }
    #[DataProvider('runs')]
    public function testCommandClassificationFlow(string $scenario): void
    {
        $dir = sys_get_temp_dir().'/topic-command-'.bin2hex(random_bytes(5));
        mkdir($dir);
        $em = Support::em($dir.'/state.sqlite');
        $mail = new FakeMailbox();
        $ai = new FakeAi();
        $mail->messages = [1 => str_replace('Subject: dokumenty', 'Subject: Pytanie', Support::mime(1, 'Jakie papiery zabrać?'))];
        $d = Support::config();
        $d['reply_rules']['matching'] = 'ai';
        if ($scenario === 'mixed') {
            $d['reply_rules']['editing'] = ['mode' => 'mixed'];
        }
        if ($scenario === 'manual') {
            $ai->matchResult = ['outcome' => 'manual','confidence' => 'uncertain','topic_ids' => []];
        }
        if ($scenario === 'invalid') {
            $ai->matchResult['topic_ids'] = ['invented'];
        }
        if ($scenario === 'transport') {
            $ai->failMatch = true;
        }
        if ($scenario === 'excluded') {
            $mail->messages = [1 => Support::mime(1, 'wykluczone')];
        }
        if ($scenario === 'pending') {
            $mail->crashAfterAppend = true;
        }
        try {
            $run = new CommandTester(Support::command(Support::writeConfig($dir, $d), $mail, $ai, $em));
            $run->execute($scenario === 'dry' ? ['--dry-run' => true] : []);
            if (in_array($scenario, ['manual','invalid','excluded'], true)) {
                self::assertSame(0, $mail->appends);
                self::assertSame(1, $mail->flags);
                self::assertSame(0, $ai->calls);
                self::assertSame($scenario === 'excluded' ? 0 : 1, $ai->matchCalls);
                self::assertSame($scenario === 'invalid' ? 1 : 0, $run->getStatusCode());
                self::assertSame('manual', $em->getConnection()->fetchOne('SELECT status FROM processing_record'));
                $run->execute([]);
                self::assertSame($scenario === 'excluded' ? 0 : 1, $ai->matchCalls);
            } elseif ($scenario === 'transport') {
                self::assertSame(1, $run->getStatusCode());
                self::assertSame(0, $mail->appends);
                self::assertStringNotContainsString('SECRET', $run->getDisplay());
                $ai->failMatch = false;
                $run->execute([]);
                self::assertSame(1, $mail->appends);
                self::assertSame(2, $ai->matchCalls);
            } elseif ($scenario === 'dry') {
                self::assertSame(0, $run->getStatusCode());
                self::assertSame(1, $ai->matchCalls);
                self::assertSame(0, $mail->appends);
                self::assertSame(0, $mail->flags);
                self::assertSame(0, (int)$em->getConnection()->fetchOne('SELECT COUNT(*) FROM processing_record'));
            } else {
                self::assertSame(1, $mail->appends);
                self::assertSame(1, $ai->matchCalls);
                self::assertSame(1, $ai->calls);
                self::assertSame($scenario === 'mixed' ? 1 : 0, $ai->rewriteCalls);
                $run->execute([]);
                self::assertSame(1, $mail->appends);
                self::assertSame(1, $ai->matchCalls);
            }
        } finally {
            $em->getConnection()->close();
            foreach (glob($dir.'/*') as $f) {
                unlink($f);
            }rmdir($dir);
        }
    }
    public static function runs(): array
    {
        return [['success'],['manual'],['invalid'],['excluded'],['transport'],['dry'],['mixed'],['pending']];
    }
}
