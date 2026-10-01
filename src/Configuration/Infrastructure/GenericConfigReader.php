<?php

declare(strict_types=1);

namespace App\Configuration\Infrastructure;

use App\Configuration\Domain\{ConfigValidator, CompanyConfig};
use Symfony\Component\Yaml\Yaml;

/** Versioned public format. All three documents form one validated package. */
final class GenericConfigReader
{
    public function read(string $directory): CompanyConfig
    {
        $documents = [];
        foreach (ConfigSnapshotStore::FILES as $name) {
            try {
                $document = Yaml::parseFile($directory.'/'.$name, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
            } catch (\Symfony\Component\Yaml\Exception\ParseException $e) {
                throw new \InvalidArgumentException($name.': invalid YAML at line '.$e->getParsedLine(), previous: $e);
            } catch (\Throwable $e) {
                throw new \RuntimeException('Cannot read '.$name, previous: $e);
            }
            if (!is_array($document) || ($document !== [] && array_is_list($document))) {
                throw new \InvalidArgumentException($name.': expected mapping');
            }
            $documents[$name] = $document;
        }
        $company = $documents['company.yaml'];
        $knowledge = $documents['knowledge.yaml'];
        $pricing = $documents['pricing.yaml'];
        $this->keys($company, ['schema_version', 'company', 'reply'], 'company.yaml');
        if ($company['schema_version'] !== 1) {
            throw new \InvalidArgumentException('company.yaml.schema_version: supported version is 1');
        }
        $this->keys($knowledge, ['topics', 'facts', 'clarifications'], 'knowledge.yaml');
        $this->keys($pricing, ['prices'], 'pricing.yaml');
        if (!is_array($company['reply'])) {
            throw new \InvalidArgumentException('company.yaml.reply: expected mapping');
        }
        if (array_key_exists('topics', $company['reply']) || array_key_exists('clarifications', $company['reply'])) {
            throw new \InvalidArgumentException('Topics and clarifications belong in knowledge.yaml');
        }
        $rules = $company['reply'] + ['topics' => $knowledge['topics'], 'clarifications' => $knowledge['clarifications']];
        try {
            return (new ConfigValidator())->validate(['office' => $company['company'], 'knowledge' => ['facts' => $knowledge['facts']], 'pricing' => $pricing, 'reply_rules' => $rules]);
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException(strtr($e->getMessage(), ['reply_rules.yaml.topics' => 'knowledge.yaml.topics', 'reply_rules.yaml.clarifications' => 'knowledge.yaml.clarifications', 'reply_rules.yaml' => 'company.yaml.reply', 'office.yaml' => 'company.yaml.company']), previous: $e);
        }
    }
    private function keys(array $data, array $expected, string $file): void
    {
        $actual = array_keys($data);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new \InvalidArgumentException($file.': expected fields '.implode(', ', $expected));
        }
    }
}
