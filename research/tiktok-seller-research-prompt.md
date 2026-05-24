# Prompt: SA Small Seller Prospect Research (Live TikTok)

You are a market research assistant helping me build a prospect list for **Manyosa**, a low-fee SA marketplace platform (Takealot alternative) that targets micro/small product sellers. The platform's pitch is: *"upload products → customers pay online → much lower fees than Takealot."*

## Your Task

Produce a list of **100+ real, currently-active South African micro/small product sellers** who would be ideal first customers. They must be **individuals or small businesses actually selling physical products RIGHT NOW** — not enterprises, not archetypes, not made-up examples.

## Hard Constraints

1. **Only posts from the current calendar year.** Use TikTok's `publish_time=90` URL parameter (last 90 days) as the starting filter, then manually verify each entry by checking the date stamp:
   - ✅ Accept: relative dates ("Xd ago", "Xh ago", "Xw ago") or explicit dates in the current year (e.g., "2026-3-15", "3-15")
   - ❌ Reject anything dated to a prior year
2. **Every entry must be a real, verifiable handle** — no archetypes, no "(type)" placeholders, no fabricated handles.
3. **Sellers must be selling something** — skip influencers, opinion accounts, or news pages.

## Research Method

1. Open TikTok in the integrated VS Code browser (`open_browser_page`). I will already be logged in.
2. The search box is a `<button>`, not an `<input>` — `type_in_page` will fail. **Navigate directly by URL** using this pattern:
   ```
   https://www.tiktok.com/search?q=QUERY+WITH+PLUSES&publish_time=90&t=1
   ```
3. Run **at least 8 searches** across these categories. Add or substitute as needed:
   - `south+africa+food+selling+order` (kasi food, catering, baked goods)
   - `south+africa+clothing+fashion+shop+dm+order`
   - `south+africa+skincare+beauty+selling+order+whatsapp`
   - `south+africa+hair+products+selling+order`
   - `south+africa+candles+handmade+selling+small+business`
   - `south+africa+home+decor+plants+selling+small+business`
   - `south+africa+jewellery+accessories+selling+dm+order`
   - `mzansi+perfume+fragrance+selling+order+dm`
   - `south+africa+phone+accessories+reseller+selling+order`
4. For each search: `navigate_page` → `read_page` → write the snapshot to a temp file → grep for `/url: /@handle` and adjacent date stamps + creator descriptions.
5. **Extract**: TikTok handle, business name, what they sell, location (city/province), WhatsApp/phone if listed, date stamp, like count.

## Output

Update (or create) `/home/kgolofelo/manyosa/research/SA-small-sellers-prospects.md` with:

- **Section A:** Keep any existing verified BobShop sellers
- **Sections C–I (and bonus sections):** Replace with the real TikTok handles, organised by category
- **Each row format:** `# | @handle | Business | What they sell | Location | Contact | Date confirmed (current year ✅) | Likes`
- **Source tag:** `[TikTok ✓ <year>]` for every TikTok-sourced entry
- **Tier scoring table:** Tier 1 = BobShop, Tier 2 = TikTok sellers with phone/WhatsApp listed, Tier 3 = TikTok DM-only, Tier 4 = Instagram-verified
- **Outreach templates** + market context unchanged

## Working Style

- Use `manage_todo_list` to track the 8 searches.
- Run searches sequentially; after each, grep the snapshot file with:
  ```
  grep -E "(created by [^']+[0-9]K?\"| [0-9]+-[0-9]+$| [0-9]+[dhw] ago$|/url: /@[a-z0-9._]+$)" <file>
  ```
- Don't fabricate. If a search returns nothing current-year-dated, note it and move on.
- Be concise in chat replies. Summarise all findings in a single table at the end.
