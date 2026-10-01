<?php

declare(strict_types=1);

namespace App\Configuration\Infrastructure\Migration;

use App\Configuration\Infrastructure\ConfigLoader;
use App\Configuration\Domain\EditingSettings;
use Symfony\Component\Yaml\Yaml;

/** Converts owner-facing YAML to the existing schema; ConfigLoader validates the result. */
final class PolishConfigReader
{
    private array $paths = [];

    public function read(string $directory): array
    {
        $files = [];
        foreach (['kancelaria', 'sprawy', 'cennik'] as $name) {
            try {
                $files[$name] = Yaml::parseFile($directory.'/'.$name.'.yaml', Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
            } catch (\Symfony\Component\Yaml\Exception\ParseException $e) {
                $this->fail($name.'.yaml', 'błąd składni YAML w wierszu '.$e->getParsedLine().'; sprawdź wcięcia, wartości i powtórzone klucze');
            } catch (\Throwable) {
                $this->fail($name.'.yaml', 'nie można odczytać pliku YAML');
            }
            $this->map($files[$name], $name.'.yaml');
        }
        $officeFields = ['nazwa' => 'name', 'email' => 'sender_address', 'podpis' => 'signature', 'jezyk' => 'reply_language', 'strefa_czasowa' => 'timezone'];
        $ruleFields = ['styl' => 'tone', 'powitanie' => 'greeting', 'zakonczenie' => 'closing', 'wykluczenia' => 'excluded_terms', 'obsluga_reczna' => 'manual_terms'];
        $o = $files['kancelaria'];
        $this->keys($o, ['nazwa', 'email', 'podpis', 'styl', 'powitanie', 'zakonczenie'], ['jezyk', 'strefa_czasowa', 'kontakt', 'godziny_pracy', 'wykluczenia', 'obsluga_reczna', 'redakcja', 'brzmienie', 'dopasowanie'], 'kancelaria.yaml');
        $o += ['jezyk' => 'pl', 'strefa_czasowa' => 'Europe/Warsaw', 'kontakt' => [], 'wykluczenia' => [], 'obsluga_reczna' => []];
        $data = ['office' => [], 'knowledge' => ['facts' => []], 'pricing' => ['prices' => []], 'reply_rules' => ['topics' => [], 'clarifications' => []]];
        $this->paths = ['office.yaml' => 'kancelaria.yaml', 'reply_rules.yaml' => 'kancelaria.yaml', 'knowledge.yaml' => 'sprawy.yaml', 'pricing.yaml' => 'cennik.yaml'];
        foreach ($officeFields as $pl => $en) {
            $data['office'][$en] = $o[$pl];
            $this->paths['office.yaml.'.$en] = 'kancelaria.yaml.'.$pl;
        }
        foreach ($ruleFields as $pl => $en) {
            $data['reply_rules'][$en] = $o[$pl];
            $this->paths['reply_rules.yaml.'.$en] = 'kancelaria.yaml.'.$pl;
        }
        $this->keys($o['kontakt'], [], ['telefon', 'adres', 'www'], 'kancelaria.yaml.kontakt');
        $data['office']['contact'] = [];
        foreach (['telefon' => 'phone', 'adres' => 'address', 'www' => 'website'] as $pl => $en) {
            if (array_key_exists($pl, $o['kontakt'])) {
                $data['office']['contact'][$en] = $o['kontakt'][$pl];
            }
            $this->paths['office.yaml.contact.'.$en] = 'kancelaria.yaml.kontakt.'.$pl;
        }
        $this->paths['office.yaml.contact'] = 'kancelaria.yaml.kontakt';
        $editing = $this->editing($o, 'kancelaria.yaml', 'reply_rules.yaml.editing');
        if ($editing !== []) {
            $data['reply_rules']['editing'] = $editing;
        }
        if (array_key_exists('dopasowanie', $o)) {
            $data['reply_rules']['matching'] = $o['dopasowanie'];
        }
        $this->paths['reply_rules.yaml.matching'] = 'kancelaria.yaml.dopasowanie';
        $this->paths['reply_rules.yaml.topics'] = 'sprawy.yaml';
        $cases = $files['sprawy'];
        if (array_key_exists('godziny_pracy', $o)) {
            if (array_key_exists('godziny', $cases)) {
                $this->fail('sprawy.yaml.godziny', 'usuń ten wpis albo godziny_pracy w kancelaria.yaml; nie definiuj godzin w obu miejscach');
            }
            $cases['godziny'] = ['rozpoznaj' => ['godziny', 'godzinach', 'otwarcia', 'czynna', 'czynne', 'otwarte', 'sobotę', 'soboty', 'niedzielę', 'weekend'], 'odpowiedz' => $o['godziny_pracy'], 'redakcja' => 'doslowna'];
        }
        foreach ($cases as $id => $case) {
            $path = 'sprawy.yaml.'.$id;
            $this->id($id, $path);
            $this->keys($case, ($o['dopasowanie'] ?? 'slowa') === 'ai' ? [] : ['rozpoznaj'], ['rozpoznaj', 'opis', 'odpowiedz', 'zapytaj', 'od', 'do', 'zastepuje', 'redakcja', 'brzmienie'], $path);
            $case += ['rozpoznaj' => []];
            $this->editing($case, $path, 'reply_rules.yaml.topics.'.$id.'.editing');
            $topicPath = 'reply_rules.yaml.topics.'.$id;
            $this->paths[$topicPath] = $path;
            $this->paths[$topicPath.'.description'] = $path.'.opis';
            $this->paths[$topicPath.'.terms'] = $path.'.rozpoznaj';
            $this->paths[$topicPath.'.supersedes'] = $path.'.zastepuje';
            $data['reply_rules']['topics'][$id] = ['terms' => $case['rozpoznaj']];
            if (array_key_exists('opis', $case)) {
                $data['reply_rules']['topics'][$id]['description'] = $case['opis'];
            }
            if (array_key_exists('zastepuje', $case)) {
                $data['reply_rules']['topics'][$id]['supersedes'] = $case['zastepuje'];
            }
            if ((array_key_exists('od', $case) || array_key_exists('do', $case)) && !array_key_exists('odpowiedz', $case) && !array_key_exists('zapytaj', $case)) {
                $this->fail($path, 'daty wymagają odpowiedzi lub pytania');
            }
            foreach (['odpowiedz' => 'fact', 'zapytaj' => 'question'] as $field => $type) {
                if (!array_key_exists($field, $case)) {
                    continue;
                }
                $block = ['text' => $case[$field], 'topics' => [$id]];
                foreach (['od' => 'valid_from', 'do' => 'valid_until'] as $pl => $en) {
                    if (array_key_exists($pl, $case)) {
                        $block[$en] = $case[$pl];
                    }
                }
                $blockId = 'sprawa.'.$id.'.'.$type;
                if ($type === 'fact') {
                    $data['knowledge']['facts'][$blockId] = $block;
                    $canonical = 'knowledge.yaml.facts.'.$blockId;
                } else {
                    $data['reply_rules']['clarifications'][$blockId] = $block;
                    $canonical = 'reply_rules.yaml.clarifications.'.$blockId;
                }
                $editing = $this->editing($case, $path, $canonical.'.editing');
                if ($editing !== []) {
                    if ($type === 'fact') {
                        $data['knowledge']['facts'][$blockId]['editing'] = $editing;
                    } else {
                        $data['reply_rules']['clarifications'][$blockId]['editing'] = $editing;
                    }
                }
                $this->paths[$canonical] = $path;
                $this->paths[$canonical.'.text'] = $path.'.'.$field;
                foreach (['od' => 'valid_from', 'do' => 'valid_until'] as $pl => $en) {
                    $this->paths[$canonical.'.'.$en] = $path.'.'.$pl;
                }
                if ($id === 'godziny' && array_key_exists('godziny_pracy', $o)) {
                    $this->paths[$canonical] = $this->paths[$canonical.'.text'] = 'kancelaria.yaml.godziny_pracy';
                }
            }
        }
        foreach ($files['cennik'] as $caseId => $entries) {
            $path = 'cennik.yaml.'.$caseId;
            $this->id($caseId, $path);
            if (!array_key_exists($caseId, $cases)) {
                $this->fail($path, 'nieznana sprawa; dodaj ją w sprawy.yaml');
            }
            $this->map($entries, $path);
            foreach ($entries as $id => $entry) {
                $p = $path.'.'.$id;
                $this->id($id, $p);
                $this->keys($entry, ['warunki', 'od', 'do'], ['opis', 'kwota', 'waluta'], $p);
                $fixed = array_key_exists('kwota', $entry);
                if ($fixed && !array_key_exists('waluta', $entry)) {
                    $this->fail($p.'.waluta', 'wymagane pole dla kwoty');
                }
                if (!$fixed && array_key_exists('waluta', $entry)) {
                    $this->fail($p.'.waluta', 'waluta wymaga kwoty');
                }
                if (!$fixed && !array_key_exists('opis', $entry)) {
                    $this->fail($p.'.opis', 'wymagana treść reguły cenowej');
                }
                $text = array_key_exists('opis', $entry) ? $entry['opis'] : 'Cena: {kwota} {waluta}.';
                if (is_string($text)) {
                    // Only the Polish placeholders are part of the owner-facing contract.
                    $rest = str_replace($fixed ? ['{kwota}', '{waluta}'] : [], '', $text);
                    if (strpbrk($rest, '{}') !== false) {
                        $this->fail($p.'.opis', 'dozwolone są tylko {kwota} i {waluta} przy stałej kwocie');
                    }
                    $text = strtr($text, ['{kwota}' => '{amount}', '{waluta}' => '{currency}']);
                }
                $priceId = 'cena.'.$caseId.'.'.$id;
                $price = ['kind' => $fixed ? 'fixed' : 'rule', 'text' => $text, 'topics' => [$caseId], 'conditions' => $entry['warunki'], 'valid_from' => $entry['od'], 'valid_until' => $entry['do']];
                if ($fixed) {
                    $price['amount'] = $entry['kwota'];
                    $price['currency'] = $entry['waluta'];
                }
                $data['pricing']['prices'][$priceId] = $price;
                $canonical = 'pricing.yaml.prices.'.$priceId;
                $this->paths[$canonical] = $p;
                foreach (['text' => 'opis', 'conditions' => 'warunki', 'valid_from' => 'od', 'valid_until' => 'do', 'amount' => 'kwota', 'currency' => 'waluta'] as $en => $pl) {
                    $this->paths[$canonical.'.'.$en] = $p.'.'.$pl;
                }
            }
        }
        return $data;
    }

    private function editing(array $input, string $source, string $canonical): array
    {
        $settings = [];
        foreach (['redakcja' => 'mode', 'brzmienie' => 'style'] as $pl => $en) {
            if (array_key_exists($pl, $input)) {
                $settings[$en] = $input[$pl];
            }
            $this->paths[$canonical.'.'.$en] = $source.'.'.$pl;
        }
        try {
            EditingSettings::validate(LegacyVocabulary::editing($settings), $canonical);
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException($this->sourceError($e->getMessage()));
        }
        return $settings;
    }

    public function sourceError(string $error): string
    {
        $paths = $this->paths;
        uksort($paths, static fn ($a, $b) => strlen($b) <=> strlen($a));
        foreach ($paths as $canonical => $source) {
            if (str_starts_with($error, $canonical.':') || str_starts_with($error, $canonical.'.')) {
                $error = $source.substr($error, strlen($canonical));
                break;
            }
        }
        return strtr($error, [
            'approved nonempty text required; replace placeholders' => 'wpisz zatwierdzony, niepusty tekst; zastąp oznaczenia wymaganych danych',
            'control characters are not allowed' => 'znaki sterujące są niedozwolone',
            'expected mapping' => 'oczekiwano sekcji z nazwanymi polami',
            'expected list with at least one entry' => 'wpisz listę z co najmniej jedną pozycją',
            'expected list' => 'wpisz listę', 'duplicate entry' => 'powtórzona pozycja',
            'expected a single email address' => 'wpisz jeden poprawny adres e-mail',
            'must be a single line' => 'wpisz tekst w jednej linii',
            'expected a language tag such as pl or pl-PL' => 'wpisz kod języka, np. pl',
            'invalid timezone' => 'nieprawidłowa strefa czasowa',
            'expected quoted YYYY-MM-DD date' => 'wpisz datę w cudzysłowie: RRRR-MM-DD',
            'invalid calendar date' => 'nieprawidłowa data', 'reversed validity interval' => 'data od nie może być późniejsza niż do',
            'unsupported placeholder' => 'niedozwolony symbol zastępczy',
            '.instructions' => '.wskazowki', '.formality' => '.formalnosc', '.length' => '.dlugosc',
            'expected another existing topic ID' => 'wskaż inną istniejącą sprawę',
            'cyclic topic precedence' => 'cykliczne zastępowanie spraw',
            'fixed prices require a quoted decimal amount and three-letter currency' => 'wpisz kwotę w cudzysłowie z kropką i kod waluty z trzech wielkich liter',
            'fixed price text must contain {amount} and {currency} exactly once' => 'opis musi zawierać {kwota} i {waluta} dokładnie po jednym razie',
        ]);
    }
    private function keys(mixed $value, array $required, array $optional, string $path): void
    {
        $this->map($value, $path);
        foreach ($required as $key) {
            if (!array_key_exists($key, $value)) {
                $this->fail($path.'.'.$key, 'brak wymaganego pola');
            }
        }
        foreach ($value as $key => $_) {
            if (!in_array($key, [...$required, ...$optional], true)) {
                $this->fail($path.'.'.$key, 'nieznane pole');
            }
        }
    }
    private function map(mixed $value, string $path): void
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            $this->fail($path, 'oczekiwano sekcji z nazwanymi polami (pusta sekcja: {})');
        }
    }
    private function id(mixed $value, string $path): void
    {
        if (!is_string($value) || !preg_match('/^[a-z][a-z0-9_-]*$/D', $value)) {
            $this->fail($path, 'nazwa musi zaczynać się małą literą; używaj a-z, cyfr, _ i -');
        }
    }
    private function fail(string $path, string $message): never
    {
        throw new \InvalidArgumentException($path.': '.$message);
    }
}
