---
paths:
  - 'app/Domain/*/DTOs/**'
---

# D T Os

## campaign_id in Lead/Contact/Deal DTOs means "absent = leave alone"
LeadData, ContactData and DealData write campaign_id only when the input array has the key (setsCampaign), unlike their other fields which toAttributes() writes as null to clear. Capture forms, ingestion, chat, Meta updates and the API never send a campaign, and treating that as "clear" would wipe the attribution an ad click recorded. Forms send it via WithCampaignAttribution (app/Domain/Campaigns/Concerns), which also re-checks the chosen campaign through Campaign::visibleTo() — `exists:campaigns,id` alone ignores tenant and access level.
