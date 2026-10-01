<?php

declare(strict_types=1);

namespace App\Tests;

use App\Configuration\Infrastructure\{ConfigLoader, ConfigSnapshotStore};
use App\Configuration\Infrastructure\Migration\{LegacyImporter, LegacyConfigLoader};
use App\MailProcessing\Domain\ProcessingRecord;
use App\MailProcessing\Infrastructure\StateStore;
use App\Reply\Domain\{FactSelector, ReplyRenderer};
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;

require_once __DIR__.'/Support.php';
final class GenericConfigurationTest extends TestCase
{
    private string $directory;
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/company-config-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }
    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }
    private function copyExample(string $example): string
    {
        $target = $this->directory.'/'.$example;
        mkdir($target);
        foreach (ConfigSnapshotStore::FILES as $file) {
            copy(dirname(__DIR__).'/examples/'.$example.'/'.$file, $target.'/'.$file);
        }
        return $target;
    }
    public function testIndependentCompaniesUseSameCodeAndSeparateState(): void
    {
        $paths = [$this->copyExample('notary'), $this->copyExample('repair')];
        $configurations = array_map(static fn ($path) => (new ConfigLoader($path))->load(), $paths);
        self::assertNotSame($configurations[0]->company['sender_address'], $configurations[1]->company['sender_address']);
        self::assertNotSame($configurations[0]->hash, $configurations[1]->hash);
        foreach ($paths as $index => $path) {
            $config = $configurations[$index];
            $topic = array_key_first($config->rules['topics']);
            $selection = (new FactSelector())->select($config, '', new \DateTimeImmutable(), [$topic]);
            self::assertCount(1, $selection->facts);
            $em = Support::em($path.'/state.sqlite');
            $record = new ProcessingRecord('same-mailbox', '1', 1, $config->hash);
            $record->status = 'drafted';
            (new StateStore($em))->save($record);
            $em->getConnection()->close();
        }
        $first = new ConfigSnapshotStore($paths[0]);
        $second = new ConfigSnapshotStore($paths[1]);
        $untouched = $second->read();
        $current = $first->read();
        $current['files']['company.yaml'] .= "\n# Changed only in company one\n";
        $first->save($current['files'], $current['version']);
        self::assertSame($untouched, $second->read());
        self::assertFileDoesNotExist($paths[1].'/CURRENT');
    }
    public function testUnknownSchemaAndFieldsAndDanglingReferencesAreRejected(): void
    {
        $path = $this->copyExample('repair');
        $store = new ConfigSnapshotStore($path);
        $before = $store->read();
        $mutations = [
            ['company.yaml', 'schema_version', 99],
            ['company.yaml', 'unexpected', true],
            ['knowledge.yaml', 'facts', ['bad' => ['text' => 'Approved text', 'topics' => ['missing']]]],
        ];
        foreach ($mutations as [$file, $key, $value]) {
            $files = $before['files'];
            $data = Yaml::parse($files[$file]);
            $data[$key] = $value;
            $files[$file] = Yaml::dump($data, 20);
            try {
                $store->save($files, $before['version']);
                self::fail('Invalid configuration activated.');
            } catch (\InvalidArgumentException) {
                self::assertSame($before, $store->read());
            }
        }
    }
    private function legacy(): string
    {
        $path = $this->directory.'/legacy';
        mkdir($path);
        $documents = [
            'kancelaria' => ['nazwa' => 'Fictional Company', 'email' => 'contact@example.test', 'podpis' => "Zażółć gęślą jaźń\nApproved signature", 'styl' => 'Professional', 'powitanie' => 'Hello,', 'zakonczenie' => 'Regards,', 'redakcja' => 'mieszana', 'brzmienie' => ['dlugosc' => 'krotka','wskazowki' => 'Keep approved technical terms.']],
            'sprawy' => ['dokumenty' => ['rozpoznaj' => ['dokumenty'], 'odpowiedz' => 'Approved response.', 'zapytaj' => 'Which document?', 'redakcja' => 'doslowna']],
            'cennik' => ['dokumenty' => ['fee' => ['kwota' => '12.34','waluta' => 'PLN','warunki' => 'Only for the approved scope.', 'od' => '2026-01-01','do' => '2026-12-31']]],
        ];
        foreach ($documents as $name => $data) {
            file_put_contents($path.'/'.$name.'.yaml', "# Original comment\n".Yaml::dump($data, 20));
        }
        return $path;
    }
    public function testMigrationPreservesContentRenderingAndCompletedProcessingState(): void
    {
        $source = $this->legacy();
        $old = (new LegacyConfigLoader($source))->load();
        $before = [];
        foreach (glob($source.'/*.yaml') as $file) {
            $before[$file] = hash_file('sha256', $file);
        }
        $em = Support::em($this->directory.'/state.sqlite');
        $mail = new FakeMailbox();
        $mail->messages = [1 => Support::mime()];
        $record = new ProcessingRecord($mail->identity(), '1', 1, $old->hash);
        $record->status = 'drafted';
        $state = new StateStore($em);
        $state->save($record);
        $state->checkValidity($mail->identity(), '1', false);
        $dbHash = hash_file('sha256', $this->directory.'/state.sqlite');
        try {
            $destination = $this->directory.'/generic';
            $report = (new LegacyImporter())->import($source, $destination);
            $new = (new ConfigLoader($destination))->load();
            self::assertSame($old->hash, $new->hash);
            self::assertTrue($report['content_preserved']);
            self::assertFalse($report['processing_state_changed']);
            self::assertSame('short', $new->rules['editing']['style']['length']);
            foreach ($before as $file => $hash) {
                self::assertSame($hash, hash_file('sha256', $file));
                self::assertSame($hash, hash_file('sha256', $destination.'/legacy-archive/'.basename($file)));
            }
            self::assertSame($dbHash, hash_file('sha256', $this->directory.'/state.sqlite'));
            $selector = new FactSelector();
            $date = new \DateTimeImmutable('2026-10-01T12:00:00Z');
            $proposal = ['outcome' => 'reply','blocks' => ['fact:sprawa.dokumenty.fact','price:cena.dokumenty.fee'],'used_fact_ids' => ['sprawa.dokumenty.fact'],'used_price_ids' => ['cena.dokumenty.fee'],'clarification_ids' => [],'reason' => 'supported'];
            $renderer = new ReplyRenderer();
            self::assertSame($renderer->render($proposal, $selector->select($old, 'dokumenty', $date), $old), $renderer->render($proposal, $selector->select($new, 'dokumenty', $date), $new));
            $ai = new FakeAi();
            $command = new CommandTester(Support::command(new ConfigLoader($destination), $mail, $ai, $em));
            $command->execute([]);
            self::assertSame(0, $command->getStatusCode());
            self::assertSame(0, $mail->appends);
            self::assertSame(0, $ai->calls);
            self::assertSame('drafted', $state->find($mail->identity(), '1', 1)->status);
        } finally {
            $em->getConnection()->close();
        }
    }
    public function testImporterRefusesOverwriteAndArchivesPreviousSnapshots(): void
    {
        $source = $this->legacy();
        mkdir($source.'/versions');
        $id = str_repeat('a', 32);
        mkdir($source.'/versions/'.$id);
        foreach (glob($source.'/*.yaml') as $file) {
            copy($file, $source.'/versions/'.$id.'/'.basename($file));
        }
        file_put_contents($source.'/versions/'.$id.'/metadata.json', '{"action":"initial"}');
        file_put_contents($source.'/CURRENT', $id."\n");
        $destination = $this->directory.'/imported';
        $importer = new LegacyImporter();
        $importer->import($source, $destination);
        self::assertFileExists($destination.'/legacy-archive/versions/'.$id.'/kancelaria.yaml');
        $snapshot = (new ConfigSnapshotStore($destination))->read();
        $this->expectException(\InvalidArgumentException::class);
        try {
            $importer->import($source, $destination);
        } finally {
            self::assertSame($snapshot, (new ConfigSnapshotStore($destination))->read());
        }
    }
    public function testImporterRejectsDestinationInsideSourceBeforeWriting(): void
    {
        $source = $this->legacy();
        $this->expectException(\InvalidArgumentException::class);
        try {
            (new LegacyImporter())->import($source, $source.'/nested');
        } finally {
            self::assertDirectoryDoesNotExist($source.'/nested');
            self::assertSame([], glob($source.'/.import-*'));
        }
    }
    public function testMalformedLegacyStyleIsAValidationErrorBeforeWriting(): void
    {
        $source = $this->legacy();
        $file = $source.'/kancelaria.yaml';
        $data = Yaml::parseFile($file);
        $data['brzmienie']['dlugosc'] = ['invalid'];
        file_put_contents($file, Yaml::dump($data, 20));
        $destination = $this->directory.'/invalid-import';
        $this->expectException(\InvalidArgumentException::class);
        try {
            (new LegacyImporter())->import($source, $destination);
        } finally {
            self::assertDirectoryDoesNotExist($destination);
        }
    }

}
