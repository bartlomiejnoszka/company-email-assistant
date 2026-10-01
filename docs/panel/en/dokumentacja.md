# Configuration reference

## Company

`schema_version: 1` is required. `company` requires `name`, `sender_address`, `reply_language`, `signature`, `contact`, `timezone`. Contact supports `phone`, `address`, `website`.

`reply` requires `tone`, `greeting`, `closing`, `excluded_terms`, `manual_terms`. Optional `matching` is `keywords` (default) or `ai`. Optional `editing` has `mode` (`literal` or `mixed`) and `style`.

Style keys: `formality` (formal, neutral, casual), `length` (short, standard, detailed), `addressing` (polite, impersonal), `layout` (paragraphs, list, automatic), `tone` (factual, warm, empathetic), `vocabulary` (simple, technical), `instructions` (approved text, at most 1000 characters). Defaults are neutral, standard, polite, automatic, warm, simple.

## Knowledge

Required mappings: `topics`, `facts`, `clarifications`. Topics require `terms` (a list); optional `description` and `supersedes` reference existing topics. AI matching requires a description or terms. Keywords matching requires terms.

Facts and clarifications require `text` and `topics`. Optional `valid_from`, `valid_until` use quoted YYYY-MM-DD dates; optional `editing` overrides global editing. IDs are stable lowercase identifiers and globally unique across facts, prices and clarifications. Content may be in any configured reply language; changing the language setting does not translate it.

## Pricing

Required mapping: `prices`. Each entry requires `kind` (fixed or rule), `text`, `topics`, `conditions`, `valid_from`, `valid_until`. Fixed prices additionally require `amount` (quoted decimal) and `currency` (three uppercase letters); text must contain `{amount}` and `{currency}` exactly once. Rule prices have approved text and no amount/currency. Price conditions and signatures are never rewritten.

## Validation

Unknown fields, duplicate keys, invalid IDs, dangling references, cycles, invalid dates, unsupported placeholders and required-data markers are rejected. Empty content mappings are supported. Configuration cannot contain credentials. Interface language is separate from reply language.
