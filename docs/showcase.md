# Engineering walkthrough

[Company Email Assistant](../README.md) prepares mailbox drafts from approved business knowledge. Its design keeps company content in configuration, AI proposals subject to local rules, and uncertain mailbox operations recoverable. This walkthrough links those decisions to implementation and tests.

## A fictional repair enquiry

The [repair example](../examples/repair/company.yaml) uses `matching: keywords` and `editing.mode: literal`. Its [knowledge file](../examples/repair/knowledge.yaml) defines the `repair` topic with `repair` and `device` terms, and one approved fact, `repair.checklist`:

> Please bring the device and its purchase receipt.

For the enquiry "What should I bring for a device repair?", selection makes that fact available to the AI proposal step. A valid proposal references `fact:repair.checklist`; it does not supply replacement prose. The renderer adds the configured greeting, closing and signature.

Illustrative draft, assuming the proposal selects that fact:

```text
Hello,

Please bring the device and its purchase receipt.

Kind regards,

Example Repair Workshop
```

This is an illustration of rendering approved content, not a recorded OpenAI response or a guarantee of a draft on every run. The example has no approved prices or clarification blocks, so it cannot demonstrate a quotation or a clarification reply.

Two other enquiries show where processing stops:

| Enquiry | Rule and result |
| --- | --- |
| "Do you offer parking?" | Neither configured topic term matches. Selection raises `no_matching_topic`; mailbox processing records manual handling and flags the message rather than creating a draft. |
| "I have a dispute about a repair." | `dispute` is a configured manual term. The pipeline raises `office_manual_rule` before AI generation; mailbox processing records manual handling and flags the message. |

Other configurations can use AI-assisted topic matching. That mode validates returned topic IDs and requires a high-confidence match; uncertain or invalid classification becomes manual handling. The [topic matching tests](../tests/TopicMatchingTest.php) cover those boundaries. The repair example above deliberately uses its existing keyword rules.

## Keeping domain rules independent

Domain rules use plain PHP. Application services coordinate ports; infrastructure implements provider calls, storage and mailbox operations. Company identity, topics, approved facts and prices live in the three YAML files rather than customer-specific PHP branches.

For example, [FactSelector](../src/Reply/Domain/FactSelector.php) applies manual rules, topic selection and content validity dates. [ProposalValidator](../src/Reply/Domain/ProposalValidator.php) rejects unknown content references and inconsistent proposal fields. [ReplyRenderer](../src/Reply/Domain/ReplyRenderer.php) assembles approved blocks, including complete price conditions and the configured signature. AI-generated proposals cannot establish whether a price applies to an individual case.

The [architecture tests](../tests/ArchitectureTest.php) check domain and application source for prohibited framework, provider and infrastructure dependencies. The [company configuration tests](../tests/GenericConfigurationTest.php) exercise separate fictional companies using the same code and isolated state.

## Sharing services across adapters

[ResponseGenerator](../src/Reply/Application/ResponseGenerator.php) is independent of mailbox operations. The console's `ProcessMailbox` service uses it to produce draft content; the MCP `test_mail_response` tool uses it to preview a response from active or proposed configuration. A preview calls AI but does not access mail, create drafts or activate configuration. It is not a mailbox dry run.

[ConfigurationManager](../src/Configuration/Application/ConfigurationManager.php) serves both HTTP panel editing and MCP configuration tools. Both use the same package validation, expected-version checks and snapshot storage. Authentication differs at the adapters: the panel uses owner sessions, while MCP requires bearer tokens with configuration scopes.

The [response preview tests](../tests/MailResponseTest.php) exercise the shared pipeline and verify that previews preserve configuration bytes and history. The [MCP tests](../tests/McpTest.php) cover authentication, scopes, version conflicts and configuration editing.

## Activating a complete configuration snapshot

[ConfigSnapshotStore](../src/Configuration/Infrastructure/ConfigSnapshotStore.php) treats `company.yaml`, `knowledge.yaml` and `pricing.yaml` as one package. A save takes a writer lock and compares the submitted version with the active version. A stale form or MCP request is rejected instead of overwriting newer edits.

The store writes and validates a complete candidate before atomically replacing the `CURRENT` pointer. Each operation loads one immutable configuration, so it does not combine files from different versions. Previous snapshots retain their exact YAML text; restore publishes another version and preserves history. Stable content IDs identify the approved blocks across configuration changes and migration.

The [snapshot tests](../tests/ConfigSnapshotStoreTest.php) cover exact text preservation, full-package restore, invalid candidates, stale saves/restores and failed activation. This implementation assumes a local filesystem shared by readers and writers; it is not a distributed configuration store.

## Recovering uncertain mailbox operations

[ProcessMailbox](../src/MailProcessing/Application/ProcessMailbox.php) validates configuration before mailbox access and takes a run lock to prevent overlapping processing. Source identity is mailbox identity plus `UIDVALIDITY` and `UID`. SQLite stores processing metadata and identifiers, not raw correspondence; a changed mailbox epoch stops the run for reconciliation.

Before IMAP `APPEND`, the service persists `append_pending` and a stable draft Message-ID. If the process loses the result, the next run searches for that draft. Exactly one match reconciles the record as drafted. No match or multiple matches becomes `append_unknown`; an unavailable search leaves the record pending. Neither case automatically repeats `APPEND`. This can leave a message requiring manual reconciliation even if the original write never happened.

The optional allowlisted test-send path similarly persists `send_pending` before SMTP submission. An uncertain result becomes `send_unknown` and is not automatically resent. Completed messages remain terminal after configuration changes; updates affect future work rather than regenerating existing drafts.

The [mailbox orchestration tests](../tests/CommandTest.php) simulate crashes before and after draft creation, unavailable reconciliation, repeated runs, UIDVALIDITY changes and overlapping runs. The [test delivery tests](../tests/TestAutoReplyTest.php) cover narrow recipient matching and uncertain-send recovery using fakes.

## What validation can and cannot establish

The default literal mode renders approved content. Optional mixed editing rewrites eligible blocks with structural and lexical checks; prices, conditions, greetings, closings and signatures remain protected. Those guards do not establish semantic equivalence, and staff review remains required. See the [reply tests](../tests/ReplyTest.php) and [mixed editing tests](../tests/MixedReplyTest.php).

Attachment contents and prior thread context are not interpreted. The initial OpenAI adapter sends enquiry text and relevant configured content to an external API. Processing metadata stays local, but self-hosting the application does not imply local AI inference or prove provider-side data-retention behaviour.

Ordinary tests use fakes and temporary data without external accounts or API keys. They verify local contracts and recovery behaviour, not live-model accuracy. The opt-in live-mailbox test requires a dedicated seeded account. [Local verification](verification.md) records a dated check of a fresh installation; it is not a continuously updated benchmark or deployment claim.

## Further reading

- [Architecture and module boundaries](architecture.md)
- [Tests and the optional live-mailbox check](testing.md)
- [MCP setup and response previews](mcp.md)
- [Docker deployment and persistent storage](../deploy/README.md)
- [Configuration reference](panel/en/dokumentacja.md)
- [Migration and state compatibility](migration.md)
