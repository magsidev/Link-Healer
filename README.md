# Link Healer

Link Healer is an advanced, high-performance WordPress plugin designed to automatically find, validate, and dynamically resolve broken internal and external links across posts, pages, custom post types, and block editor canvases.

## Key Features

1. **KPI Administration Dashboard**: Unified React-inspired dashboard with gradient metric cards, crawl control console, and a paginated audit listing.
2. **On-Page Gutenberg Integration**: Real-time block editor settings sidebar letting editors swap broken URLs inside active paragraphs, buttons, or custom block attributes directly on the canvas.
3. **3-Tier Matcher Engine**: Automatically determines target redirects using exact slug matching, levenshtein/similar_text fuzzy comparisons, and an OpenAI `gpt-4o-mini` API fallback.
4. **False Positive Defenses**: Employs spoofed browser headers, redirect following, and handles `403`/`503` scraper blocks safely without throwing false alarms.
5. **Locked Concurrency Schedulers**: Uses transient locks during discovery and crawls to prevent concurrent overlaps and memory crashes.

## Installation

1. Download the plugin folder and compress it into a `.zip` archive.
2. Navigate to **WordPress Dashboard > Plugins > Add New > Upload Plugin**.
3. Select `link-healer.zip` and click **Install Now**, then click **Activate**.
4. Access the custom dashboard under the **Link Healer** sidebar menu.
