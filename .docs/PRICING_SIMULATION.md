# Pricing Simulation — Phase 7 (2026-10-29)

> **HYPOTHETICAL:** every price, plan, and mixture below is a **hypothesis for review**, produced by a reproducible simulation (`php artisan pricing:simulate`, inputs in `config/pricing_simulation.php`). Nothing here is an established market price, no competitor pricing was consulted, and **no billing, credit deduction, or payment processing exists in the codebase**. Cost basis and validation: [COST_VALIDATION.md](COST_VALIDATION.md).

## 1. Formulas (fixed definitions used everywhere below)

```
Revenue       = subscription revenue attributable to the modeled usage
                (+ extra-credit revenue where stated)
VariableCost  = provider operations + render infrastructure + storage
                + bandwidth (measured or simulated at labeled assumptions)
GrossProfit   = Revenue − VariableCost
GrossMargin   = GrossProfit / Revenue × 100%
RequiredRevenue = VariableCost / (1 − Target Gross Margin)
```

Gross margin is **not** markup and **not** net profit: taxes, engineering salaries, fixed hosting, support, refunds, and chargebacks are excluded. Payment processing (~2.9% + $0.30 — **hypothesis**) is reported separately as `margin_net_of_payment_pct`.

## 2. Simulated video cost (USD per video)

Provider rates verified 2026-10-29 (§2 of COST_VALIDATION). TTS is the only unresolved component (audio-tokens-per-second τ undocumented; simulated τ=50 central, range 25–100). Retry/regeneration waste is priced conservatively at full operation cost. **All figures are simulations, not observations.**

| Scenario | Scenes/chars/TTS lines | Text $ | Images $ | TTS $ (low–high) | Infra $ | Retry+Regen $ | **Total (low / central / high)** |
|---|---|---:|---:|---|---:|---:|---|
| 30 s efficient | 3 / 1 / 3 | 0.0107 | 0.0881 | 0.0109 (0.0055–0.0217) | 0.0018 | 0 | **0.106 / 0.111 / 0.122** |
| 30 s typical | 4 / 2 / 6 | 0.0116 | 0.1264 | 0.0217 (0.0109–0.0433) | 0.0018 | 0.0242 | **0.151 / 0.186 / 0.207** |
| 30 s heavy | 5 / 4 / 11 | 0.0125 | 0.1781 | 0.0399 (0.0201–0.0795) | 0.0018 | 0.1043 | **0.212 / 0.336 / 0.376** |
| 60 s efficient | 6 / 1 / 6 | 0.0131 | 0.1629 | 0.0217 (0.0109–0.0433) | 0.0033 | 0 | **0.190 / 0.201 / 0.223** |
| 60 s typical | 8 / 2 / 12 | 0.0148 | 0.2261 | 0.0435 (0.0219–0.0867) | 0.0033 | 0.0442 | **0.266 / 0.332 / 0.375** |
| 60 s heavy | 10 / 4 / 22 | 0.0165 | 0.3027 | 0.0797 (0.0401–0.1589) | 0.0033 | 0.1847 | **0.363 / 0.587 / 0.666** |
| 90 s efficient | 9 / 1 / 9 | 0.0156 | 0.2377 | 0.0326 (0.0164–0.0650) | 0.0049 | 0 | **0.275 / 0.291 / 0.323** |
| 90 s typical | 12 / 2 / 18 | 0.0180 | 0.3258 | 0.0652 (0.0328–0.1300) | 0.0049 | 0.0641 | **0.382 / 0.478 / 0.543** |
| 90 s heavy | 15 / 4 / 33 | 0.0206 | 0.4273 | 0.1196 (0.0602–0.2384) | 0.0049 | 0.2652 | **0.513 / 0.838 / 0.956** |

Reading: **images dominate** (≈55–65% of central cost), TTS is second and carries the uncertainty band, text is negligible, infrastructure is negligible (0.5–2%). Grounding: scene density from the one observed real render (64 s → 8 scenes); per-image output ≈ 362 tokens and per-line audio ≈ 6.0 s from real-key dev observations (n=10 and n=15); retry rates are hypotheses with no production evidence yet.

## 3. Key assumptions and their evidence (full labels in `config/pricing_simulation.php`)

| Assumption | Value | Evidence |
|---|---|---|
| Provider rates | config/ai.php lists | **verified** 2026-10-29 |
| Story/plan tokens | 750/1300, 950+60/char in, 162/scene out | **observed/derived** (n=4 dev calls) |
| Image tokens | refs 500 in; scenes 1981 in (70% image-share hypothesis); 362 out | **observed** (n=10), split share **hypothesis** |
| TTS chars & audio length | 60/110 chars, 6.0 s audio/line | **observed** (n=18/8/15) |
| **TTS audio tokens per second (τ)** | **50 central [25–100]** | **hypothesis — no documentation exists** |
| Retry/regeneration rates | 0–30% by profile | **hypothesis** (no production data) |
| Render wall time | 0.25 × duration | **derived** (n=1 real render) |
| Infra rates (render/storage/bandwidth) | $0.0000111/s, $0.023/GB·mo, $0.09/GB, 2 downloads | **hypothesis** |
| Payment fees | 2.9% + $0.30 | **hypothesis** |

## 4. Recommended credit design: **hybrid (base by duration + regen surcharges)**

Compared options:

1. **One fixed credit cost per duration** (e.g. 1/2/3 credits for 30/60/90 s) — simplest UX, but a heavy 90 s video costs ≈ 2.9× an efficient one, so flat pricing either overcharges light users or under-recovers heavy ones.
2. **Fully cost-weighted credits** (price every option in credits by simulated cost) — most accurate, worst UX (users must understand τ uncertainty and quality multipliers).
3. **Hybrid (recommended):** base credits by duration band (**30 s = 1, 60 s = 2, 90 s = 3**) plus **+1 credit per explicitly regenerated image or voice line**. Provider-side retries are NEVER charged to users (our cost of doing business — they are often not provably billed). Expected consumption: efficient ≈ base, typical ≈ base + 1, heavy ≈ base + 5 credits.

A credit is a unit of product usage (one 30 s-equivalent generation band + regen surcharges), **not** advertised as a fixed monetary value. The internal reference for pack economics is cost-per-credit ≈ **$0.11** (typical 60 s central cost ÷ 3 expected credits) — internal figure, never user-facing.

## 5. Candidate tiers (PROVISIONAL HYPOTHESES)

| Tier (provisional) | Price/mo | Included credits | Worst-case included videos (scenario) | Variable cost of full consumption | Gross profit | Gross margin | Net of payment fees |
|---|---:|---:|---|---:|---:|---:|---:|
| Starter | $9 | 8 | 2× 90 s typical | $0.96 | $8.04 | **89.4%** | 83.1% |
| Creator | $19 | 25 | 6× 90 s typical | $2.87 | $16.13 | **84.9%** | 80.4% |
| Pro | $49 | 80 | 20× 90 s typical | $9.56 | $39.44 | **80.5%** | 77.0% |

- Full scenario matrices (3 tiers × 9 scenarios): `php artisan pricing:simulate`. Margins across ALL 27 scenario cells range **80.5%–96.3%**.
- Worst case per tier = burning all credits on 90 s typical videos (more mid-cost videos exceed fewer max-cost ones at floor() granularity).
- **Heavy-user exposure:** a Pro user producing 10 × 90 s heavy videos (8 credits each) costs $8.38 variable → 82.9% margin. No tested tier loses money on included usage.
- Effective revenue per video (price ÷ included videos of that scenario): e.g. Pro 60 s typical ≈ $1.88/video vs $0.33 variable cost.

## 6. Additional credit packs (PROVISIONAL HYPOTHESES)

| Pack | Price | Revenue/credit | Cost/credit (ref) | Gross margin |
|---|---:|---:|---:|---:|
| 20 credits | $9 | $0.45 | $0.111 | **75.4%** |
| 60 credits | $24 | $0.40 | $0.111 | **72.3%** |

Packs keep a healthy margin while costing users less per credit than subscription credits' effective rate. Heavy-profile consumption raises cost/credit (90 s heavy ≈ $0.105/credit at 8 credits) — pack margins stay ≥ ~70% except under combined shocks (§7).

## 7. Margin targets and sensitivity

**Required revenue per heavy 90 s video** (`VariableCost / (1 − GM)`, central $0.8375):

| Target gross margin | 60% | 70% | 80% |
|---|---:|---:|---:|
| Required revenue/video | $2.09 | $2.79 | $4.19 |
| Covered by (credits × pack rate) | 3 cr pack-rate $0.40 → $1.20 ❌ | 5–7 cr ❌ | 8+ cr ✓ (90 s heavy = 8 cr) |

Interpretation: at the pack rate (~$0.40–0.45/credit), a 60–70% margin target on heavy videos needs ~5–7 credits per heavy video rather than the proposed 3 base credits — i.e. **regeneration surcharges are what carry the margin on heavy usage**, which the hybrid design provides. Under base-credits-only pricing the 60 s/90 s heavy profiles would land below a 70% target at pack rates.

**Shock grid (Pro tier, full credit consumption as 90 s heavy, 10 videos/mo; 27 combinations of provider-rate × TTS-τ × retry multipliers):**

| Combination (rate× / τ× / retry×) | Pro gross margin |
|---|---:|
| 1 / 1 / 1 (central) | 82.9% |
| 1.2 / 1 / 1 | 79.5% |
| 1 / 1 / 4 | 66.7% |
| 2 / 1 / 1 | 65.9% |
| 2 / 2 / 4 (worst tested) | **28.6%** |

**None of the 27 tested combinations is unprofitable.** Break-even for Pro requires ≈ **5.9×** central provider costs (variable ≥ $49 across 10 videos) — far outside the tested shock range, but a real possibility only under a catastrophic provider price increase; provider list prices would need to ~6×.

**Infrastructure shock:** at 10× the hypothesized infra rates, a 90 s video still carries only **$0.049** infra cost — infrastructure cannot threaten margins at any plausible rate (it is 0.5–2% of variable cost).

**Subscriber mixture (hypothesis 40 Starter / 40 Creator / 20 Pro per 100 subs, each consuming exactly included credits):** revenue $2,100, variable $303 → **85.6% gross margin**. Assumes no pack purchases; packs only improve margin.

**Where the plans WOULD become unprofitable:** (a) provider rates ≥ ~6× while plans stay unchanged; (b) giving away large fixed credit allowances WITHOUT regen surcharges while usage concentrates in heavy profiles AND τ proves ≥ 100; (c) unbounded free re-renders at heavy infra rates — currently impossible (single-render lock + explicit regenerate), but the rule matters.

## 8. Highest-risk assumptions (ranked) and what to measure next

1. **TTS τ (audio tokens per second)** — entire TTS line is unresolved; a τ=100 reality doubles central TTS vs τ=50. *Measure:* provider usage-dashboard audio tokens ÷ recorded audio seconds after the first real billing cycle (audio seconds already measured in `assets.meta.duration_ms`).
2. **Retry/regeneration rates** — drive up to 32% of heavy-profile cost; pure hypothesis today. *Measure:* `provider_calls.attempt>1` share + fingerprint-based regeneration counts after launch.
3. **Image input token split** — simulated at 70% image-share (hypothesis); the split is persisted in `usage_details` since `7b25e37`, so real data replaces this automatically.
4. **Render wall-time scaling** — n=1 observation (0.25×); production hardware will differ. *Measure:* `generation_jobs.started_at→finished_at` distribution.
5. **Subscriber mixture & pack attach rate** — pure hypothesis; needs real cohort data.
6. **Bandwidth + storage retention** — unmeasured; negligible at hypothesis rates but unbounded without download accounting (byte counting on the download endpoint is the missing instrumentation).

## 9. Verdict

- The proposed $9/$19/$49 tiers with 8/25/80 hybrid credits clear a 70% gross-margin target on every tested scenario, with the heavy-user worst case at **80.5%** and the worst tested shock combination at **28.6%** (still profitable).
- **The product is NOT ready to charge customers:** TTS actuals remain unmeasurable through the API (τ unresolved), production retry/regeneration rates are unknown, bandwidth is unmeasured, and no payment infrastructure exists. Re-run `php artisan pricing:simulate` against real `provider_calls` data after a measured pilot before finalizing prices.
