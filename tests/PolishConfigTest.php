<?php

declare(strict_types=1);

namespace App\Tests;

use App\Configuration\Infrastructure\Migration\LegacyConfigLoader as ConfigLoader;
use App\Reply\Domain\FactSelector;
use App\Reply\Domain\ReplyRenderer;
use App\Reply\Domain\ProposalValidator;
use App\MailProcessing\Domain\ManualHandling;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;

require_once __DIR__.'/Support.php';
final class PolishConfigTest extends TestCase
{
    private string $dir;
    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/polish-office-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }
    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') as $file) {
            unlink($file);
        } rmdir($this->dir);
    }
    private static function data(): array
    {
        return [
            'kancelaria' => ['nazwa' => 'Test Office', 'email' => 'office@example.test', 'podpis' => 'Test signature', 'styl' => 'Formalnie', 'powitanie' => 'Dzień dobry,', 'zakonczenie' => 'Z poważaniem,'],
            'sprawy' => ['dokumenty' => ['rozpoznaj' => ['dokumenty'], 'odpowiedz' => 'Odpowiedź testowa.', 'zapytaj' => 'Jakiego dokumentu dotyczy pytanie?']],
            'cennik' => [],
        ];
    }
    private function write(array $data): ConfigLoader
    {
        foreach ($data as $name => $value) {
            file_put_contents($this->dir.'/'.$name.'.yaml', Yaml::dump($value, 10));
        }
        return new ConfigLoader($this->dir);
    }
    public function testAiMatchingAcceptsDescriptionsWithoutKeywordVariants(): void
    {
        $d = self::data();
        $d['kancelaria']['dopasowanie'] = 'ai';
        $d['sprawy']['dokumenty']['opis'] = 'Pytania o przygotowanie dokumentów przed wizytą.';
        unset($d['sprawy']['dokumenty']['rozpoznaj']);
        $c = $this->write($d)->load();
        self::assertSame('ai', $c->rules['matching']);
        self::assertSame([], $c->rules['topics']['dokumenty']['terms']);
        self::assertSame($d['sprawy']['dokumenty']['opis'], $c->rules['topics']['dokumenty']['description']);
    }
    public function testAiMatchingStillRequiresDescriptionOrExamples(): void
    {
        $d = self::data();
        $d['kancelaria']['dopasowanie'] = 'ai';
        unset($d['sprawy']['dokumenty']['rozpoznaj']);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sprawy.yaml.dokumenty.opis');
        $this->write($d)->load();
    }
    public function testEditingSettingsAreValidatedAndInherited(): void
    {
        $d = self::data();
        $d['kancelaria']['redakcja'] = 'mieszana';
        $d['kancelaria']['brzmienie'] = ['formalnosc' => 'formalna', 'ton' => 'zyczliwy'];
        $d['sprawy']['dokumenty']['brzmienie'] = ['dlugosc' => 'krotka'];
        $d['kancelaria']['godziny_pracy'] = 'Godziny testowe.';
        $c = $this->write($d)->load();
        self::assertSame('mixed', $c->rules['editing']['mode']);
        self::assertSame('short', $c->knowledge['sprawa.dokumenty.fact']['editing']['style']['length']);
        self::assertSame('literal', $c->knowledge['sprawa.godziny.fact']['editing']['mode']);
    }
    public function testDefaultsStableIdsAndHashIndependentOfComments(): void
    {
        $loader = $this->write(self::data());
        $c = $loader->load();
        self::assertSame('pl', $c->company['reply_language']);
        self::assertSame('Europe/Warsaw', $c->company['timezone']);
        self::assertSame(['sprawa.dokumenty.fact'], array_keys($c->knowledge));
        self::assertSame(['sprawa.dokumenty.question'], array_keys($c->rules['clarifications']));
        file_put_contents($this->dir.'/sprawy.yaml', "\n# Komentarz właściciela\n", FILE_APPEND);
        self::assertSame($c->hash, $loader->load()->hash);
        $data = self::data();
        $data['sprawy']['dokumenty']['odpowiedz'] = 'Nowa odpowiedź.';
        $changed = $this->write($data)->load();
        self::assertNotSame($c->hash, $changed->hash);
        self::assertSame(array_keys($c->knowledge), array_keys($changed->knowledge));
    }
    public function testEmptyCasesAndPricesAreValid(): void
    {
        $data = self::data();
        $data['sprawy'] = [];
        $c = $this->write($data)->load();
        self::assertSame([], $c->knowledge);
        self::assertSame([], $c->pricing);
    }
    public function testFixedPriceRuleValidityAndClarificationRenderThroughExistingPipeline(): void
    {
        $data = self::data();
        $data['cennik']['dokumenty'] = [
            'kwota' => ['kwota' => '12.34', 'waluta' => 'PLN', 'warunki' => 'Wyłącznie warunek testowy.', 'od' => '2026-09-26', 'do' => '2026-09-26'],
            'regula' => ['opis' => 'Reguła testowa bez obliczeń.', 'warunki' => 'Po potwierdzeniu zakresu.', 'od' => '2026-09-26', 'do' => '2026-09-26'],
        ];
        $data['sprawy']['dokumenty'] += ['od' => '2026-09-26', 'do' => '2026-09-26'];
        $c = $this->write($data)->load();
        $selector = new FactSelector();
        $s = $selector->select($c, 'DOKUMENTY', new \DateTimeImmutable('2026-09-25T22:00:00Z'));
        self::assertCount(2, $s->prices);
        $proposal = ['outcome' => 'clarify', 'blocks' => ['price:cena.dokumenty.kwota', 'price:cena.dokumenty.regula', 'clarification:sprawa.dokumenty.question'], 'used_fact_ids' => [], 'used_price_ids' => ['cena.dokumenty.kwota', 'cena.dokumenty.regula'], 'clarification_ids' => ['sprawa.dokumenty.question'], 'reason' => 'missing_details'];
        $body = (new ReplyRenderer())->render($proposal, $s, $c);
        self::assertStringContainsString('Cena: 12.34 PLN.', $body);
        self::assertStringContainsString('Wyłącznie warunek testowy.', $body);
        self::assertStringContainsString('Reguła testowa bez obliczeń.', $body);
        self::assertStringContainsString('Jakiego dokumentu', $body);
        $expired = $selector->select($c, 'dokumenty', new \DateTimeImmutable('2026-09-26T22:00:00Z'));
        self::assertSame([], $expired->blocks());
        $this->expectException(ManualHandling::class);
        (new ProposalValidator())->validate($proposal, $expired);
    }
    public function testHoursAndSpecificCasePrecedence(): void
    {
        $data = self::data();
        $data['kancelaria']['godziny_pracy'] = 'Godziny testowe: 9–17.';
        $data['sprawy']['szczegoly'] = ['rozpoznaj' => ['szczególne dokumenty'], 'odpowiedz' => 'Szczegóły.', 'zastepuje' => ['dokumenty']];
        $c = $this->write($data)->load();
        $s = (new FactSelector())->select($c, 'Szczególne dokumenty. Godziny otwarcia?', new \DateTimeImmutable());
        self::assertSame(['szczegoly', 'godziny'], $s->topics);
        self::assertSame('Godziny testowe: 9–17.', $s->facts['sprawa.godziny.fact']['text']);
    }
    #[DataProvider('invalid')]
    public function testInvalidOwnerInputHasOriginalPath(string $file, array $path, mixed $value, string $expected): void
    {
        $data = self::data();
        $node = &$data[$file];
        foreach ($path as $part) {
            $node = &$node[$part];
        }
        $node = $value;
        unset($node);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expected);
        $this->write($data)->load();
    }
    public static function invalid(): iterable
    {
        yield ['kancelaria', ['dopasowanie'], 'bad', 'kancelaria.yaml.dopasowanie'];
        yield ['sprawy', ['dokumenty', 'opis'], null, 'sprawy.yaml.dokumenty.opis'];
        yield ['sprawy', ['dokumenty', 'opis'], str_repeat('a', 1001), 'sprawy.yaml.dokumenty.opis'];
        yield ['kancelaria', ['redakcja'], 'swobodna', 'kancelaria.yaml.redakcja'];
        yield ['kancelaria', ['brzmienie'], null, 'kancelaria.yaml.brzmienie'];
        yield ['kancelaria', ['brzmienie', 'formalnosc'], 'bad', 'kancelaria.yaml.brzmienie.formalnosc'];
        yield ['kancelaria', ['brzmienie', 'unknown'], true, 'kancelaria.yaml.brzmienie.unknown'];
        yield ['sprawy', ['dokumenty', 'redakcja'], null, 'sprawy.yaml.dokumenty.redakcja'];
        yield ['sprawy', ['dokumenty', 'brzmienie', 'wskazowki'], '__REQUIRED__', 'sprawy.yaml.dokumenty.brzmienie.wskazowki'];
        yield ['kancelaria', ['nazwa'], '__REQUIRED__', 'kancelaria.yaml.nazwa'];
        yield ['kancelaria', ['email'], 'bad', 'kancelaria.yaml.email'];
        yield ['kancelaria', ['haslo'], 'secret', 'kancelaria.yaml.haslo'];
        yield ['kancelaria', ['kontakt', 'telefon'], [], 'kancelaria.yaml.kontakt.telefon'];
        yield ['kancelaria', ['godziny_pracy'], null, 'kancelaria.yaml.godziny_pracy'];
        yield ['kancelaria', ['obsluga_reczna'], null, 'kancelaria.yaml.obsluga_reczna'];
        yield ['sprawy', ['dokumenty', 'rozpoznaj'], [], 'sprawy.yaml.dokumenty.rozpoznaj'];
        yield ['sprawy', ['dokumenty', 'odpowiedz'], ['invalid'], 'sprawy.yaml.dokumenty.odpowiedz'];
        yield ['sprawy', ['dokumenty', 'odpowiedz'], '{invented}', 'sprawy.yaml.dokumenty.odpowiedz'];
        yield ['sprawy', ['dokumenty', 'zapytaj'], null, 'sprawy.yaml.dokumenty.zapytaj'];
        yield ['sprawy', ['dokumenty', 'od'], '2026-02-30', 'sprawy.yaml.dokumenty.od'];
        yield ['sprawy', ['dokumenty', 'zastepuje'], ['missing'], 'sprawy.yaml.dokumenty.zastepuje'];
        yield ['sprawy', ['dokumenty', 'unknown'], true, 'sprawy.yaml.dokumenty.unknown'];
        yield ['cennik', ['missing'], [], 'cennik.yaml.missing'];
        $p = ['kwota' => '12.34', 'waluta' => 'PLN', 'warunki' => 'Test', 'od' => '2026-01-01', 'do' => '2026-12-31'];
        yield ['cennik', ['dokumenty', 'test'], array_diff_key($p, ['do' => 1]), 'cennik.yaml.dokumenty.test.do'];
        yield ['cennik', ['dokumenty', 'test'], array_replace($p, ['kwota' => 12.34]), 'cennik.yaml.dokumenty.test'];
        yield ['cennik', ['dokumenty', 'test'], array_replace($p, ['opis' => null]), 'cennik.yaml.dokumenty.test.opis'];
        yield ['cennik', ['dokumenty', 'test'], array_replace($p, ['opis' => '{amount} {currency}']), 'cennik.yaml.dokumenty.test.opis'];
        yield ['cennik', ['dokumenty', 'test'], array_replace($p, ['od' => '2027-01-01']), 'cennik.yaml.dokumenty.test'];
        yield ['cennik', ['dokumenty', 'test'], ['opis' => 'Test', 'warunki' => 'Test', 'waluta' => 'PLN', 'od' => '2026-01-01', 'do' => '2026-12-31'], 'cennik.yaml.dokumenty.test.waluta'];
    }
    public function testCyclesRejectedWithPolishPath(): void
    {
        $d = self::data();
        $d['sprawy']['dokumenty']['zastepuje'] = ['inne'];
        $d['sprawy']['inne'] = ['rozpoznaj' => ['inne'], 'zastepuje' => ['dokumenty']];
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sprawy.yaml.dokumenty.zastepuje');
        $this->write($d)->load();
    }
    public function testDuplicateHoursRejected(): void
    {
        $d = self::data();
        $d['kancelaria']['godziny_pracy'] = '9–17';
        $d['sprawy']['godziny'] = ['rozpoznaj' => ['godziny']];
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sprawy.yaml.godziny');
        $this->write($d)->load();
    }
    public function testPartialPolishFormatDoesNotFallBack(): void
    {
        $loader = $this->write(self::data());
        unlink($this->dir.'/cennik.yaml');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cennik.yaml');
        $loader->load();
    }
    public function testDuplicateYamlStopsCommandBeforeMailbox(): void
    {
        $loader = $this->write(self::data());
        file_put_contents($this->dir.'/sprawy.yaml', "dokumenty: {}\ndokumenty: {}\n");
        $em = Support::em($this->dir.'/state.sqlite');
        $mail = new FakeMailbox();
        $ai = new FakeAi();
        try {
            $tester = new CommandTester(Support::command($loader, $mail, $ai, $em));
            $tester->execute([]);
            self::assertSame(2, $tester->getStatusCode());
            self::assertStringContainsString('sprawy.yaml', $tester->getDisplay());
            self::assertSame(0, $mail->connections);
            self::assertSame(0, $ai->calls);
        } finally {
            $em->getConnection()->close();
        }
    }
    public function testPolishProfileDryRunThenDraftAndRepeat(): void
    {
        $em = Support::em($this->dir.'/state.sqlite');
        $mail = new FakeMailbox();
        $mail->messages = [1 => Support::mime()];
        $ai = new FakeAi();
        $ai->proposal = Support::proposal(['blocks' => ['fact:sprawa.dokumenty.fact'], 'used_fact_ids' => ['sprawa.dokumenty.fact']]);
        try {
            $loader = $this->write(self::data());
            $run = new CommandTester(Support::command($loader, $mail, $ai, $em));
            $run->execute(['--dry-run' => true]);
            self::assertSame(0, $run->getStatusCode());
            self::assertSame(0, $mail->appends);
            self::assertSame(0, $mail->flags);
            self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM processing_record'));
            $run->execute([]);
            self::assertSame(0, $run->getStatusCode());
            self::assertSame(1, $mail->appends);
            $run->execute([]);
            self::assertSame(0, $run->getStatusCode());
            self::assertSame(1, $mail->appends);
        } finally {
            $em->getConnection()->close();
        }
    }
    public function testOnlyPolishFilesAreAccepted(): void
    {
        foreach (['office', 'knowledge', 'pricing', 'reply_rules'] as $name) {
            file_put_contents($this->dir.'/'.$name.'.yaml', '{}');
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('kancelaria.yaml');
        (new ConfigLoader($this->dir))->load();
    }
    public function testConfigurationChangeDoesNotRegenerateCompletedSource(): void
    {
        $em = Support::em($this->dir.'/state.sqlite');
        $mail = new FakeMailbox();
        $mail->messages = [1 => Support::mime()];
        $ai = new FakeAi();
        try {
            $legacy = Support::writeConfig($this->dir);
            $run = new CommandTester(Support::command($legacy, $mail, $ai, $em));
            $run->execute([]);
            self::assertSame(0, $run->getStatusCode());

            $run = new CommandTester(Support::command($this->write(self::data()), $mail, $ai, $em));
            $run->execute([]);
            self::assertSame(0, $run->getStatusCode());
            self::assertSame(1, $mail->appends);
            self::assertSame(1, $ai->calls);
        } finally {
            $em->getConnection()->close();
        }
    }
}
