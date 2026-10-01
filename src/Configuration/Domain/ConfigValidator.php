<?php

declare(strict_types=1);

namespace App\Configuration\Domain;

/** Validates the normalized in-memory configuration, not a second file format. */
final class ConfigValidator
{
    public function validate(array $data): CompanyConfig
    {
        $o = $data['office'];
        $this->keys($o, ['name', 'sender_address', 'reply_language', 'signature', 'contact', 'timezone'], [], 'office.yaml');
        foreach (['name', 'sender_address', 'reply_language', 'signature', 'timezone'] as $key) {
            $this->text($o[$key], 'office.yaml.'.$key);
        }
        if (!filter_var($o['sender_address'], FILTER_VALIDATE_EMAIL)) {
            $this->fail('office.yaml.sender_address', 'expected a single email address');
        }
        if (preg_match('/[\r\n]/', $o['name'])) {
            $this->fail('office.yaml.name', 'must be a single line');
        }
        if (!preg_match('/^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/D', $o['reply_language'])) {
            $this->fail('office.yaml.reply_language', 'expected a language tag such as pl or pl-PL');
        }
        try {
            new \DateTimeZone($o['timezone']);
        } catch (\Throwable) {
            $this->fail('office.yaml.timezone', 'invalid timezone');
        }
        $this->keys($o['contact'], [], ['phone', 'address', 'website'], 'office.yaml.contact');
        foreach ($o['contact'] as $k => $v) {
            $this->text($v, 'office.yaml.contact.'.$k);
        }
        $r = $data['reply_rules'];
        $this->keys($r, ['tone', 'greeting', 'closing', 'topics', 'clarifications', 'excluded_terms', 'manual_terms'], ['editing', 'matching'], 'reply_rules.yaml');
        if (array_key_exists('matching', $r) && !in_array($r['matching'], ['keywords', 'ai'], true)) {
            $this->fail('reply_rules.yaml.matching', 'expected keywords or ai');
        }
        if (array_key_exists('editing', $r)) {
            EditingSettings::validate($r['editing'], 'reply_rules.yaml.editing');
        }
        foreach (['tone', 'greeting', 'closing'] as $key) {
            $this->text($r[$key], 'reply_rules.yaml.'.$key);
        }
        foreach (['excluded_terms', 'manual_terms'] as $key) {
            $this->strings($r[$key], 'reply_rules.yaml.'.$key);
        }
        $this->map($r['topics'], 'reply_rules.yaml.topics');
        if (($r['matching'] ?? 'keywords') === 'ai' && count($r['topics']) > 100) {
            $this->fail('reply_rules.yaml.topics', 'AI matching supports at most 100 topics');
        }
        foreach ($r['topics'] as $id => $topic) {
            $p = 'reply_rules.yaml.topics.'.$id;
            $this->id($id, $p);
            $this->keys($topic, ['terms'], ['supersedes', 'description'], $p);
            $this->strings($topic['terms'], $p.'.terms', ($r['matching'] ?? 'keywords') !== 'ai');
            if (array_key_exists('description', $topic)) {
                $this->text($topic['description'], $p.'.description');
                if (mb_strlen($topic['description']) > 1000) {
                    $this->fail($p.'.description', 'description must be at most 1000 characters');
                }
            }
            if (!$topic['terms'] && !isset($topic['description'])) {
                $this->fail($p.'.description', 'provide a description or recognition terms');
            }
            $this->strings(array_key_exists('supersedes', $topic) ? $topic['supersedes'] : [], $p.'.supersedes');
            foreach ($topic['supersedes'] ?? [] as $target) {
                if ($target === $id || !isset($r['topics'][$target])) {
                    $this->fail($p.'.supersedes', 'expected another existing topic ID');
                }
            }
        }
        $visit = function (string $id, array $path = []) use (&$visit, $r): void {
            if (isset($path[$id])) {
                $this->fail('reply_rules.yaml.topics.'.$id.'.supersedes', 'cyclic topic precedence');
            }
            $path[$id] = true;
            foreach ($r['topics'][$id]['supersedes'] ?? [] as $target) {
                $visit($target, $path);
            }
        };
        foreach (array_keys($r['topics']) as $id) {
            $visit($id);
        }
        $ids = [];
        foreach (['knowledge' => 'facts', 'pricing' => 'prices', 'reply_rules' => 'clarifications'] as $file => $collection) {
            if ($file !== 'reply_rules') {
                $this->keys($data[$file], [$collection], [], $file.'.yaml');
            }
            $this->map($data[$file][$collection], $file.'.yaml.'.$collection);
            foreach ($data[$file][$collection] as $id => $item) {
                $p = $file.'.yaml.'.$collection.'.'.$id;
                $this->id($id, $p);
                if (isset($ids[$id])) {
                    $this->fail($p, 'IDs must be unique across all content');
                }
                $ids[$id] = true;
                $required = ['text', 'topics'];
                $optional = ['valid_from', 'valid_until', 'editing'];
                if ($file === 'pricing') {
                    $required = [...$required, 'kind', 'conditions', 'valid_from', 'valid_until'];
                    $optional = ['amount', 'currency'];
                }
                $this->keys($item, $required, $optional, $p);
                if (array_key_exists('editing', $item)) {
                    EditingSettings::validate($item['editing'], $p.'.editing');
                }
                $this->text($item['text'], $p.'.text');
                $this->strings($item['topics'], $p.'.topics', true);
                foreach ($item['topics'] as $topic) {
                    if (!isset($r['topics'][$topic])) {
                        $this->fail($p.'.topics', 'unknown topic ID');
                    }
                }
                foreach (['valid_from', 'valid_until'] as $key) {
                    if (array_key_exists($key, $item)) {
                        $this->date($item[$key], $p.'.'.$key);
                    }
                }
                if (isset($item['valid_from'], $item['valid_until']) && $item['valid_from'] > $item['valid_until']) {
                    $this->fail($p, 'reversed validity interval');
                }
                if ($file === 'pricing') {
                    $this->text($item['conditions'], $p.'.conditions');
                    if (!in_array($item['kind'], ['fixed', 'rule'], true)) {
                        $this->fail($p.'.kind', 'expected fixed or rule');
                    }
                    if ($item['kind'] === 'fixed') {
                        if (!isset($item['amount'], $item['currency']) || !is_string($item['amount']) || !preg_match('/^\d+(?:\.\d{1,2})?$/D', $item['amount']) || !is_string($item['currency']) || !preg_match('/^[A-Z]{3}$/D', $item['currency'])) {
                            $this->fail($p, 'fixed prices require a quoted decimal amount and three-letter currency');
                        }
                        if (substr_count($item['text'], '{amount}') !== 1 || substr_count($item['text'], '{currency}') !== 1) {
                            $this->fail($p.'.text', 'fixed price text must contain {amount} and {currency} exactly once');
                        }
                    } elseif (isset($item['amount']) || isset($item['currency'])) {
                        $this->fail($p, 'rule prices must not have amount/currency fields');
                    }
                }
                $renderable = str_replace($file === 'pricing' && $item['kind'] === 'fixed' ? ['{amount}', '{currency}'] : [], '', $item['text']);
                if (preg_match('/[{}]/', $renderable)) {
                    $this->fail($p.'.text', 'unsupported placeholder');
                }
            }
        }
        return new CompanyConfig($o, $data['knowledge']['facts'], $data['pricing']['prices'], $r);
    }

    private function keys(mixed $value, array $required, array $optional, string $path): void
    {
        $this->map($value, $path);
        foreach ($required as $key) {
            if (!array_key_exists($key, $value)) {
                $this->fail($path.'.'.$key, 'required field missing');
            }
        }
        foreach ($value as $key => $_) {
            if (!in_array($key, [...$required, ...$optional], true)) {
                $this->fail($path.'.'.$key, 'unknown field');
            }
        }
    }
    private function map(mixed $v, string $p): void
    {
        if (!is_array($v) || ($v !== [] && array_is_list($v))) {
            $this->fail($p, 'expected mapping');
        }
    }
    private function text(mixed $v, string $p): void
    {
        if (!is_string($v) || trim($v) === '' || preg_match('/__REQUIRED__|CHANGEME|TODO|<[^>]*REQUIRED[^>]*>/i', $v)) {
            $this->fail($p, 'approved nonempty text required; replace placeholders');
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $v)) {
            $this->fail($p, 'control characters are not allowed');
        }
    }
    private function strings(mixed $v, string $p, bool $nonempty = false): void
    {
        if (!is_array($v) || !array_is_list($v) || ($nonempty && !$v)) {
            $this->fail($p, 'expected list'.($nonempty ? ' with at least one entry' : ''));
        }
        foreach ($v as $i => $s) {
            $this->text($s, $p.'.'.$i);
        }
        if (count(array_unique($v)) !== count($v)) {
            $this->fail($p, 'duplicate entry');
        }
    }
    private function id(mixed $v, string $p): void
    {
        if (!is_string($v) || !preg_match('/^[a-z][a-z0-9_.-]*$/D', $v)) {
            $this->fail($p, 'invalid stable ID');
        }
    }
    private function date(mixed $v, string $p): void
    {
        if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v)) {
            $this->fail($p, 'expected quoted YYYY-MM-DD date');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        if (!$date || $date->format('Y-m-d') !== $v) {
            $this->fail($p, 'invalid calendar date');
        }
    }
    private function fail(string $path, string $message): never
    {
        throw new \InvalidArgumentException($path.': '.$message);
    }
}
