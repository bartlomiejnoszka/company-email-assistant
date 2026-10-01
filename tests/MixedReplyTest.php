<?php

declare(strict_types=1);

namespace App\Tests;

use App\MailProcessing\Domain\ManualHandling;
use App\Configuration\Domain\CompanyConfig;
use App\Reply\Domain\ReplyEditing;
use App\Reply\Domain\ReplyRenderer;
use App\Reply\Domain\Selection;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Tester\CommandTester;

require_once __DIR__.'/Support.php';
final class MixedReplyTest extends TestCase
{
    private function scenario(): array
    {
        $data = Support::config();
        $data['reply_rules']['editing'] = ['mode' => 'mixed', 'style' => ['formality' => 'formal', 'tone' => 'warm']];
        $facts = [
            'editable' => ['text' => 'Prosimy o opis planowanej czynności.', 'topics' => ['documents'], 'editing' => ['style' => ['length' => 'short']]],
            'protected' => ['text' => 'Termin wymaga osobnego potwierdzenia.', 'topics' => ['documents'], 'editing' => ['mode' => 'literal']],
        ];
        $prices = ['fee' => ['kind' => 'fixed', 'text' => 'Test: {amount} {currency}.', 'amount' => '12.34', 'currency' => 'PLN', 'conditions' => 'Wyłącznie warunek testowy.', 'topics' => ['documents']]];
        $c = new CompanyConfig($data['office'], $facts, $prices, $data['reply_rules']);
        $s = new Selection($facts, $prices, [], ['documents']);
        $p = Support::proposal(['blocks' => ['fact:editable', 'price:fee', 'fact:protected'], 'used_fact_ids' => ['editable', 'protected'], 'used_price_ids' => ['fee']]);
        return [$c, $s, $p];
    }
    public function testMixedRenderingProtectsPriceConditionsGreetingAndSignature(): void
    {
        [$c, $s, $p] = $this->scenario();
        $editable = (new ReplyEditing())->editable($p, $s, $c);
        self::assertSame(['fact:editable'], array_keys($editable));
        self::assertSame('formal', $editable['fact:editable']['style']['formality']);
        self::assertSame('short', $editable['fact:editable']['style']['length']);
        $body = (new ReplyRenderer())->render($p, $s, $c, ['outcome' => 'edited', 'edits' => [['id' => 'fact:editable', 'text' => 'Prosimy krótko opisać planowaną czynność.']]]);
        self::assertSame("Dzień dobry,\n\nProsimy krótko opisać planowaną czynność.\n\nTest: 12.34 PLN.\nWyłącznie warunek testowy.\n\nTermin wymaga osobnego potwierdzenia.\n\nZ poważaniem,\n\n".$c->company['signature'], $body);
    }
    public function testMixedModeDoesNotSilentlyFallBackWithoutEdits(): void
    {
        [$c,$s,$p] = $this->scenario();
        $this->expectException(ManualHandling::class);
        (new ReplyRenderer())->render($p, $s, $c);
    }
    #[DataProvider('invalidEdits')]
    public function testInvalidEditsNeverReachTheBody(array $result): void
    {
        [$c,$s,$p] = $this->scenario();
        $this->expectException(ManualHandling::class);
        (new ReplyRenderer())->render($p, $s, $c, $result);
    }
    public static function invalidEdits(): iterable
    {
        yield [['outcome' => 'edited', 'edits' => []]];
        yield [['outcome' => 'manual', 'edits' => []]];
        yield [['outcome' => 'edited', 'edits' => [['id' => 'fact:protected', 'text' => 'Termin potwierdzony.']]]];
        yield [['outcome' => 'edited', 'edits' => [['id' => 'price:fee', 'text' => 'Cena zmieniona.']]]];
        yield [['outcome' => 'edited', 'edits' => [['id' => 'fact:invented', 'text' => 'Tekst.']]]];
        foreach (['', 'Koszt: 999 PLN.', 'Usługa bezpłatna.', 'Rabat 50%.', 'Akt o 14:00.', 'Kontakt: hacker@example.test', 'Otwórz https://bad.example/', '<script>bad</script>', 'fact:editable', "tekst\x00", str_repeat('a', 6001)] as $text) {
            yield [['outcome' => 'edited', 'edits' => [['id' => 'fact:editable', 'text' => $text]]]];
        }
        yield [['outcome' => 'edited', 'edits' => [['id' => 'fact:editable', 'text' => 'Tekst.'], ['id' => 'fact:editable', 'text' => 'Tekst.']]]];
        yield [['outcome' => 'edited', 'edits' => [['id' => 'fact:editable', 'text' => 'Tekst.', 'reason' => 'secret']]]];
        yield [['outcome' => 'edited', 'edits' => [['id' => 'fact:editable', 'text' => 'Tekst.']], 'body' => 'Injected']];
    }
    public function testPostEditingSelectionStillRejectsExpiredBlock(): void
    {
        [$c,$s,$p] = $this->scenario();
        $facts = $s->facts;
        unset($facts['editable']);
        $this->expectException(ManualHandling::class);
        (new ReplyRenderer())->render($p, new Selection($facts, $s->prices, [], $s->topics), $c, ['outcome' => 'edited', 'edits' => [['id' => 'fact:editable','text' => 'Tekst.']]]);
    }
    public function testAllowedSourceNumberAndUrlArePreserved(): void
    {
        $source = 'Dokumenty w 2 egzemplarzach. Informacje: https://example.test/';
        $result = ['outcome' => 'edited', 'edits' => [['id' => 'fact:test', 'text' => 'Prosimy przygotować 2 egzemplarze. Informacje: https://example.test/']]];
        $edits = (new ReplyEditing())->validate($result, ['fact:test' => ['text' => $source]]);
        self::assertStringContainsString('2 egzemplarze', $edits['fact:test']);
    }
    #[DataProvider('commandScenarios')]
    public function testCommandMixedDryRunFailureAndRecovery(string $scenario): void
    {
        $dir = sys_get_temp_dir().'/mixed-command-'.bin2hex(random_bytes(5));
        mkdir($dir);
        $em = Support::em($dir.'/state.sqlite');
        $mail = new FakeMailbox();
        $mail->messages = [1 => Support::mime()];
        $ai = new FakeAi();
        $data = Support::config();
        $data['reply_rules']['editing'] = ['mode' => 'mixed'];
        $loader = Support::writeConfig($dir, $data);
        try {
            if ($scenario === 'invalid') {
                $ai->rewriteResult = ['outcome' => 'edited', 'edits' => [['id' => 'price:injected', 'text' => 'Injected']]];
            }
            if ($scenario === 'refusal') {
                $ai->rewriteResult = ['outcome' => 'manual', 'edits' => []];
            }
            if ($scenario === 'transient') {
                $ai->failRewrite = true;
            }
            if ($scenario === 'pending') {
                $mail->crashAfterAppend = true;
            }
            $run = new CommandTester(Support::command($loader, $mail, $ai, $em));
            $run->execute($scenario === 'dry' ? ['--dry-run' => true] : []);
            self::assertSame(1, $ai->rewriteCalls);
            if ($scenario === 'dry') {
                self::assertSame(0, $mail->appends);
                self::assertSame(0, $mail->flags);
                self::assertSame(0, (int)$em->getConnection()->fetchOne('SELECT COUNT(*) FROM processing_record'));
                self::assertSame(0, $run->getStatusCode());
                self::assertStringContainsString('redakcja mixed', $run->getDisplay());
            } elseif (in_array($scenario, ['invalid','refusal'], true)) {
                self::assertSame(1, $run->getStatusCode());
                self::assertSame(0, $mail->appends);
                self::assertSame(1, $mail->flags);
                self::assertSame('manual', $em->getConnection()->fetchOne('SELECT status FROM processing_record'));
                $run->execute([]);
                self::assertSame(1, $ai->rewriteCalls);
            } elseif ($scenario === 'transient') {
                self::assertSame(1, $run->getStatusCode());
                self::assertSame(0, $mail->appends);
                self::assertStringNotContainsString('SECRET', $run->getDisplay());
                $ai->failRewrite = false;
                $run->execute([]);
                self::assertSame(0, $run->getStatusCode());
                self::assertSame(1, $mail->appends);
            } else {
                self::assertSame(1, $mail->appends);
                $run->execute([]);
                self::assertSame(1, $mail->appends);
                self::assertSame(1, $ai->rewriteCalls);
                self::assertSame('drafted', $em->getConnection()->fetchOne('SELECT status FROM processing_record'));
            }
        } finally {
            $em->getConnection()->close();
            foreach (glob($dir.'/*') as $f) {
                unlink($f);
            } rmdir($dir);
        }
    }
    public static function commandScenarios(): array
    {
        return [['dry'],['success'],['invalid'],['refusal'],['transient'],['pending']];
    }
}
