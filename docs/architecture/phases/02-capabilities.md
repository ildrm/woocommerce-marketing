## Phase 2 — Marketing capability matrix

Abbreviations: **N** Native Execution; **I** Integrated Execution; **M** Management + Tracking. Each row names its primary delivery mode; reuse of another row's channel/action is allowed. Native means plugin orchestration and supported WooCommerce operations, not a claim that WordPress supplies an outbound carrier.

| Methodology | Primary support | Reusable capabilities | External execution/dependency boundary |
|---|---|---|---|
| Email marketing | I | Audience, Message, Template, Automation, Consent, Delivery, Suppression | Marketing email provider with sender authentication and verified callbacks |
| SMS marketing | I | Phone Identity, Consent, Message, QuietHours, Cost, Delivery | Carrier-capable SMS provider; country rules and pricing |
| Web push | I | Subscription Identity, Push Message, Consent, Delivery | Browser permission, HTTPS, service worker and push/VAPID adapter; permission alone is not marketing consent |
| Mobile/push integrations | I | Provider Identity, Channel, Campaign, Delivery | Existing mobile app/SDK and push provider; plugin does not create an app |
| WhatsApp | I | Approved Template, Provider Policy, Consent, Window, Delivery | Authorized messaging API with actual template/window capabilities |
| Telegram | I | Bot/channel destination, Asset, Scheduled Action | Bot API credentials and recipient/channel authorization; no scraping users |
| Social publishing | I | Asset, Variant, Calendar, Channel, Provider | Platform publishing permissions and media constraints |
| Social campaigns | I | Campaign, Audience, Content, Links, Imported Metrics | Publishing/analytics adapters where platform API permits; otherwise M |
| Advertising integrations | I | Consented audience export, Provider, Budget, Cost Import | Ad account permissions, lawful audience sharing, asynchronous API sync |
| Content marketing | N | CampaignAsset, Editorial Calendar, WordPress content references, Goals | Native WordPress posts/pages/media; syndication uses I |
| SEO-assisted marketing | I | Content briefs, LandingPage reference, CampaignGoal, Metrics | Registered adapter to installed SEO/search-console tooling; no duplicate SEO engine |
| Affiliate marketing | N | Affiliate, Program, TrackingLink, CouponRef, Commission Ledger | Payout export native; payment execution through optional provider |
| Referral marketing | N | Referral, Code, Conversion, Reward, FraudReview | Native coupon/points reward; external reward delivery uses I |
| Loyalty marketing | N | Immutable Ledger, Tier, Offer, Redemption, Expiry | Supported WooCommerce coupon/cart flow; gift/payment providers optional |
| Influencer marketing | M | Influencer, Assignment, Deliverable, Link, Coupon, Cost, Commission | Creator work and contracting external; optional publishing/payout integrations |
| Viral marketing | N | Referral ladder, Share links, Milestones, Rewards, Experiments | User-initiated share destinations; network actions only through authorized APIs |
| Buzz marketing | M | Campaign, Content, Partners, Placements, Goals, Attribution | Human/partner activity external; observed engagement imported with provenance |
| Relationship marketing | N | CustomerProfile, Lifecycle, Consent, History, Automation | Message transport I; human relationship work M |
| Lifecycle marketing | N | Stages, Segments, Triggers, Delay, Goal, Message | Transport I; lifecycle calculations native |
| Retention marketing | N | Cohorts, Repeat-purchase rules, Loyalty, Campaigns | Delivery channels I |
| Reactivation / win-back | N | Lapsed Segment, Suppression, Incentive, Automation | Delivery I; consent checked at dispatch |
| Behavioral marketing | N | Consented Event, Segment, Trigger, Personalization | First-party browser tracking subject to consent; transport I |
| Personalized marketing | N | Rules, Segment, Surface, Variant, Recommendation | Safe client fetch/native blocks; optional external personalization I |
| Product marketing | N | ProductRef, Asset, Offer, Audience, Release/stock triggers | Canonical product reads via WooCommerce |
| Growth marketing | N | Experiment, Goal, Funnel, Referral, Segments | Channels I; hypothesis management native |
| Performance marketing | I | CampaignCost, Conversion, Attribution, ROAS, Experiment | Ad/provider cost import; no causal claims from attribution |
| Direct marketing | I | Audience snapshot, Channel, Message, Opt-out | Electronic delivery provider or physical print workflow M |
| Community marketing | M | Partner/community destination, Event, Content, Campaign | Community platform integrations I where available; moderation/human work M |
| Partnership / co-marketing | M | Partner entity, Campaign assignment, Asset, TrackingLink | External partner execution; no cross-store contact transfer by default |
| Account-based concepts | N | Organization attribute, Contact group, Segment, Campaign | Optional merchant-supplied organization records; no probabilistic company matching |
| Event marketing | M | MarketingEvent, RegistrationRef, Attendance, QR, Follow-up | Venue activity external; ticket/registration system adapter I |
| Offline marketing | M | Placement, Creative, Cost, Link, Coupon, Conversion | Physical production/delivery external |
| Print campaigns | M | Print Asset, Fulfillment export, Placement, QR | Printer/postal vendor optional I; export separately permissioned |
| QR campaigns | N | QRAsset, TrackingLink, Placement, Scan Event, Goal | QR generation native; physical display external |
| Out-of-home | M | Location-specific Placement, Budget, QR, Attribution | Media buying and physical placement external |
| Street marketing | M | Assignment, Placement, Deliverable, QR, Leads | Human execution; opt-in lead capture uses native consent |
| Ambient marketing | M | Creative, Placement, Exposure estimate, Goal | Exposure estimates labeled supplied, not measured conversions |
| Experiential marketing | M | Event, Attendance, Offer, Follow-up, QR | Experience/venue execution external |
| Guerrilla marketing | M | Campaign, Placement, Cost, Assets, Tracking | Human activity outside plugin; permission/legal planning recorded as metadata |
| Sponsorship campaigns | M | Partner, Sponsorship assignment, Cost, Deliverables, QR | Contract/venue external; measurement native |
| Promotion marketing | N | Offer, Promotion, CouponRef, Eligibility rule, Goal | Native Woo coupons; advanced rule adapter only with public tested APIs |
| Cross-selling | N | Recommendation strategy, Purchase signals, Offer, Surface | Woo product APIs; message transport I |
| Upselling | N | Product/variant rules, Recommendation, Cart offer, Experiment | Server-validated Woo cart/coupon mechanics |
| Remarketing | I | Consent, Segment, Audience sync, Campaign | Provider policy/capability; first-party recontact uses native orchestration |
| Retargeting | I | Advertising purpose decision, Touchpoint, Audience sync | Authorized ad API; no covert pixel/fingerprint fallback |
| Future methodologies | N / I / M | Register additional criterion, action, strategy, channel, metric or campaign template | New providers/capabilities extend versioned contracts; no core rewrite |

### Composition examples and catalog rules

**Post-purchase cross-sell:** `commerce.order.paid` → deduplicated automation entry → delay → fresh consent/eligibility decision → recommendation of available compatible products → immutable email variant → provider delivery → click touchpoint → subsequent order conversion. Cancellation/refund can exit the run; suppression wins over audience inclusion.

**Street campaign:** campaign version → one OfflinePlacement per location → separate QRAsset/TrackingLink per placement → allowlisted same-site landing page → recorded consent-permitted scan/touchpoint → native Woo coupon → canonical purchase conversion → configured attribution result → cost comparison. Scans are observed requests, not guaranteed unique humans or causal lift.

**Referral ladder:** verified referral token → referee binding after appropriate evidence → qualified paid order → refund hold → idempotent reward ledger → milestone calculation → native points/coupon or provider reward. Self-referral and velocity flags route to review without invasive fingerprinting.

**Co-marketing:** partner and deliverables → shared campaign assets/links → partner traffic measured per identifier → consented landing-page opt-in → lifecycle journey. Partner contact sharing is a separately configured external transfer, not implied by campaign membership.

A `CampaignTemplate` is a JSON definition with declared required capabilities, suggested nodes, asset slots, goal definitions and metrics. Templates cover the full email use-case list (welcome, onboarding, drip, post-purchase, cross/upsell, cart/browse abandonment, stock/price alerts, birthday/anniversary, newsletter, broadcast, win-back and recommendations). A template never bundles a hidden permission, provider subscription, or additional consent. Unsupported provider capabilities are visible before publication.
