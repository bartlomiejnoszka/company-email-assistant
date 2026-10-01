# Dokumentacja konfiguracji

## Company

Wymagane: `schema_version: 1`, `company`, `reply`. Dane firmy: `name`, `sender_address`, `reply_language`, `signature`, `contact`, `timezone`. Preferencje: `tone`, `greeting`, `closing`, `excluded_terms`, `manual_terms`; opcjonalnie `matching` (`keywords` lub `ai`) i `editing`.

## Knowledge

Wymagane mapy: `topics`, `facts`, `clarifications`. Tematy: `terms`, opcjonalnie `description` i `supersedes`. Treści: `text`, `topics`, opcjonalnie `valid_from`, `valid_until`, `editing`. Identyfikatory muszą być unikalne; odwołania muszą istnieć.

## Pricing

Mapa `prices` może być pusta. Cena: `kind`, `text`, `topics`, `conditions`, `valid_from`, `valid_until`. Dla `fixed` wymagane są `amount` i `currency` oraz symbole `{amount}` i `{currency}` w tekście. Dla `rule` podaj dosłowną regułę bez kwoty i waluty.

## Redakcja

`mode`: `literal` lub `mixed`. `style`: `formality` (formal, neutral, casual), `length` (short, standard, detailed), `addressing` (polite, impersonal), `layout` (paragraphs, list, automatic), `tone` (factual, warm, empathetic), `vocabulary` (simple, technical), `instructions` (zatwierdzony tekst do 1000 znaków). Ustawienia treści nadpisują globalne. Ceny, warunki i podpis pozostają dosłowne.
