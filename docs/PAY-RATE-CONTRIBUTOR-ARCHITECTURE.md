# Pay Rate Contributor — architecture v0.1

Updated: 2026-11-26. **Design specification only — not a deployed data pipeline.**

## Why this exists

The public Pay Rates & Earnings Calculator currently stores its 15 market presets in a hardcoded JavaScript array on WordPress page 206. Form responses must never be inserted directly into this array.

WordPress staging form: page 1246, slug `contribute-pay-rate`, parent page 1218. It is a **draft**, not a public evidence collection endpoint.

## Contributor experience

The form asks for project, vendor, role/pool, country ISO2, project locale, contract arrangement, currency ISO4217, rate amount, pay unit, rate type, evidence month, evidence category, optional public source URL, optional non-identifying context, and explicit consent.

**Evidence is optional.** Possible categories:

- rate shown in vendor platform or project dashboard
- online agreement or contract terms
- vendor email or rate notice
- invoice or payment statement
- earnings report or payslip
- official job posting
- other screenshot or document
- self-report without documentation

A separate offer letter is **not required or preferred**. Evidence category is not equal to final evidence quality.

**Current limitation:** No file upload is enabled. Do not request screenshots via public unmoderated fields or add a free-form public upload without a private storage/review design. Jetpack can store technical metadata for form responses even if no identity fields are requested.

## Proposed private claim model

These fields are private/internal unless explicitly marked as safe for the public approved-rate snapshot:

| Field | Meaning |
| --- | --- |
| `claim_id` | Internal generated key |
| `feedback_id` | Private Jetpack record link (never published) |
| `project` | Enumerated project |
| `vendor` | Enumerated vendor |
| `role_pool` | Role / workflow |
| `country_iso2` | Country |
| `locale` | Language / locale |
| `contract_type` | Employee / independent contractor / unknown |
| `currency` | Original currency |
| `rate_amount` | Positive decimal, bounded and reviewed |
| `pay_unit` | Hour / task / 1,000 tasks / other |
| `rate_type` | Fixed, derived estimate, advertised ceiling, or unclear |
| `evidence_month` | YYYY-MM |
| `evidence_kind` | User-selected evidence description |
| `source_url_private` | Optional, manually reviewed; never publish arbitrary user URLs directly |
| `context_private` | Redacted editorial-only notes |
| `review_status` | pending / needs_evidence / approved / rejected / superseded |
| `review_evidence_level` | verified / corroborated / unverified |
| `review_notes_private` | Decision rationale, no identifiers in published output |
| `reviewed_at` | Date/time |
| `approved_for_publication` | Separate affirmative editorial decision |
| `selected_as_default` | Separate selection among valid market-specific observations |

Rate evidence should be keyed by **vendor × project × role/pool × country × locale × contract type × pay unit × rate type × effective period**, not merely vendor/country.

## Status transitions

```
Jetpack private response
    → parsed pending claim
    → needs_evidence | rejected | approved
    → if approved AND separately approved_for_publication:
         include in versioned public register
    → if selected_as_default:
         eligible for the calculator's default market preset
```

Approval is **manual**; publication is a separate approval. A verified rate for a particular employment arrangement is not a universal wage for the same country and vendor. A later claim must not overwrite older period-specific evidence; keep dated observations.

## Public approved-rates snapshot v1 (proposed)

Keep the public feed as a deliberately strict allowlist, for example:

```json
{
  "schema_version": "1.0",
  "published_version": "2026-11-26.1",
  "generated_at": "2026-11-26T00:00:00Z",
  "rates": [
    {
      "id": "us-welo",
      "vendor": "WeloData",
      "project": "Search Quality Rating",
      "role_pool": "Search Quality General Pool",
      "country_iso2": "US",
      "locale": "en-US",
      "contract_type": "Employee",
      "currency": "USD",
      "rate_amount": 14.5,
      "pay_unit": "hour",
      "rate_type": "fixed",
      "evidence_month": "2025-11",
      "source_label": "Reviewed dated payroll evidence",
      "default_preset": true
    }
  ]
}
```

The above record is an **illustrative schema example**, not an approved live record. Never publish raw feedback IDs, respondents, IPs, documents, private source links, unreviewed text, or personal metadata.

The calculator should:
1. fetch the published snapshot from a read-only, privacy-reviewed endpoint (proposed `/wp-json/ari-rates/v1/public`);
2. strictly validate schema, currency, pay unit and date before showing each record;
3. use only editorially selected eligible defaults for presets;
4. preserve the current 15 static presets as a read-only fallback until migration is validated;
5. show rate type, evidence period and original currency; never silently convert per-task amounts into hourly rates;
6. fail closed to the previous approved snapshot on a malformed/newly untrusted feed.

## Release requirements

- [ ] Verify Jetpack form markup and test one synthetic private submission.
- [ ] Check storage, editor access, permissions, consent, retention/deletion and spam behavior.
- [ ] Implement private reviewer workflow (no raw feedback in public repo or REST responses).
- [ ] Validate all fields on the server, including country/currency ISO, date, numeric range and combinations.
- [ ] Implement evidence handling/redaction for optional documents if supported and approved.
- [ ] Version and test public-only snapshot generation with a strict output allowlist.
- [ ] Import existing 15 calculator presets into a reviewed canonical register without silently changing values.
- [ ] Integrate and test API read + safe fallback and explicit source/date labels.
- [ ] Test pending/rejected/unverified claims cannot affect published rates.
- [ ] Verify rollback to previously approved version.
- [ ] Publish the form only after all safeguards and a controlled real WordPress submission test.

## Separation from NTA

The NTA Bridge is a different project: its data measure reported task availability and its private export has its own access control. Reuse design principles, not the NTA report table, schema or export credentials.
