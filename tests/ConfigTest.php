<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__.'/Support.php';
final class ConfigTest extends TestCase
{
    private string $dir;
    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/office-config-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }
    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') as $f) {
            unlink($f);
        } rmdir($this->dir);
    }
    public function testValidEmptyCollectionsAndStableHash(): void
    {
        $loader = Support::writeConfig($this->dir);
        self::assertSame($loader->load()->hash, $loader->load()->hash);
        $config = Support::config();
        $config['knowledge']['facts'] = [];
        $config['reply_rules']['topics'] = [];
        $config['reply_rules']['clarifications'] = [];
        self::assertSame([], Support::writeConfig($this->dir, $config)->load()->knowledge);
    }
    #[DataProvider('invalidConfigs')]
    public function testRejectsInvalidConfig(string $case, string $path): void
    {
        $c = Support::config();
        match ($case) {
            'precedence_unknown' => $c['reply_rules']['topics']['documents']['supersedes'] = ['absent'],
            'precedence_self' => $c['reply_rules']['topics']['documents']['supersedes'] = ['documents'],
            'precedence_null' => $c['reply_rules']['topics']['documents']['supersedes'] = null,
            'placeholder' => $c['office']['name'] = '__REQUIRED__',
            'unknown' => $c['office']['password'] = 'secret',
            'topic' => $c['knowledge']['facts']['documents']['topics'] = ['unknown'],
            'date' => $c['knowledge']['facts']['documents']['valid_from'] = '2026-02-30',
            'null_date' => $c['knowledge']['facts']['documents']['valid_until'] = null,
            'interval' => $c['knowledge']['facts']['documents'] += ['valid_from' => '2026-09-21', 'valid_until' => '2026-09-20'],
            'duplicate' => $c['reply_rules']['clarifications']['documents'] = $c['reply_rules']['clarifications']['detail'],
            'email' => $c['office']['sender_address'] = 'invalid',
            'timezone' => $c['office']['timezone'] = 'invalid',
            'price' => $c['pricing']['prices']['fee'] = ['text' => 'fee', 'topics' => ['documents'], 'kind' => 'fixed', 'conditions' => 'condition', 'valid_from' => '2026-01-01', 'valid_until' => '2026-12-31'],
        };
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($path);
        Support::writeConfig($this->dir, $c)->load();
    }
    public static function invalidConfigs(): iterable
    {
        yield ['precedence_unknown', 'supersedes'];
        yield ['precedence_self', 'supersedes'];
        yield ['precedence_null', 'supersedes'];
        yield ['placeholder', 'office.yaml.name'];
        yield ['unknown', 'office.yaml.password'];
        yield ['topic', 'knowledge.yaml.facts.documents.topics'];
        yield ['date', 'knowledge.yaml.facts.documents.valid_from'];
        yield ['interval', 'knowledge.yaml.facts.documents'];
        yield ['null_date', 'knowledge.yaml.facts.documents.valid_until'];
        yield ['duplicate', 'reply_rules.yaml.clarifications.documents'];
        yield ['email', 'office.yaml.sender_address'];
        yield ['timezone', 'office.yaml.timezone'];
        yield ['price', 'pricing.yaml.prices.fee'];
    }
    public function testTopicPrecedenceCycleIsRejected(): void
    {
        $c = Support::config();
        $c['reply_rules']['topics']['documents']['supersedes'] = ['specific'];
        $c['reply_rules']['topics']['specific'] = ['terms' => ['specific'], 'supersedes' => ['documents']];
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cyclic topic precedence');
        Support::writeConfig($this->dir, $c)->load();
    }
}
