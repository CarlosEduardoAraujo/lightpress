---
name: Kinetic Apex Editorial
colors:
  surface: '#f6faff'
  surface-dim: '#d2dbe4'
  surface-bright: '#f6faff'
  surface-container-lowest: '#ffffff'
  surface-container-low: '#ecf5fe'
  surface-container: '#e6eff8'
  surface-container-high: '#e0e9f2'
  surface-container-highest: '#dbe4ed'
  on-surface: '#141d23'
  on-surface-variant: '#5e3f3a'
  inverse-surface: '#293138'
  inverse-on-surface: '#e9f2fb'
  outline: '#936e68'
  outline-variant: '#e9bcb5'
  surface-tint: '#c00500'
  primary: '#b30400'
  on-primary: '#ffffff'
  primary-container: '#e10600'
  on-primary-container: '#fff2f0'
  inverse-primary: '#ffb4a8'
  secondary: '#5c5e63'
  on-secondary: '#ffffff'
  secondary-container: '#dedfe5'
  on-secondary-container: '#606368'
  tertiary: '#af101a'
  on-tertiary: '#ffffff'
  tertiary-container: '#d32f2f'
  on-tertiary-container: '#fff2f0'
  error: '#ba1a1a'
  on-error: '#ffffff'
  error-container: '#ffdad6'
  on-error-container: '#93000a'
  primary-fixed: '#ffdad4'
  primary-fixed-dim: '#ffb4a8'
  on-primary-fixed: '#410100'
  on-primary-fixed-variant: '#930300'
  secondary-fixed: '#e1e2e8'
  secondary-fixed-dim: '#c5c6cc'
  on-secondary-fixed: '#191c20'
  on-secondary-fixed-variant: '#44474c'
  tertiary-fixed: '#ffdad6'
  tertiary-fixed-dim: '#ffb3ac'
  on-tertiary-fixed: '#410003'
  on-tertiary-fixed-variant: '#930010'
  background: '#f6faff'
  on-background: '#141d23'
  surface-variant: '#dbe4ed'
  carbon-black: '#111418'
  carbon-surface: '#1A1E24'
  racing-red: '#E10600'
  track-asphalt: '#272727'
  pure-white: '#FFFFFF'
  canvas-cream: '#F9FAFB'
  subtle-border: '#E5E7EB'
  flag-green: '#00A859'
  flag-yellow: '#FFB800'
typography:
  display-hero:
    fontFamily: Space Grotesk
    fontSize: 48px
    fontWeight: '700'
    lineHeight: 54px
    letterSpacing: -0.03em
  display-hero-mobile:
    fontFamily: Space Grotesk
    fontSize: 32px
    fontWeight: '700'
    lineHeight: 38px
    letterSpacing: -0.02em
  headline-lg:
    fontFamily: Space Grotesk
    fontSize: 32px
    fontWeight: '700'
    lineHeight: 38px
    letterSpacing: -0.02em
  headline-lg-mobile:
    fontFamily: Space Grotesk
    fontSize: 24px
    fontWeight: '700'
    lineHeight: 30px
    letterSpacing: -0.01em
  headline-md:
    fontFamily: Space Grotesk
    fontSize: 22px
    fontWeight: '600'
    lineHeight: 28px
    letterSpacing: -0.01em
  headline-sm:
    fontFamily: Space Grotesk
    fontSize: 18px
    fontWeight: '600'
    lineHeight: 24px
  body-lg:
    fontFamily: Work Sans
    fontSize: 18px
    fontWeight: '400'
    lineHeight: 28px
  body-md:
    fontFamily: Work Sans
    fontSize: 15px
    fontWeight: '400'
    lineHeight: 22px
  body-sm:
    fontFamily: Work Sans
    fontSize: 13px
    fontWeight: '400'
    lineHeight: 18px
  label-category:
    fontFamily: Space Grotesk
    fontSize: 11px
    fontWeight: '700'
    lineHeight: 14px
    letterSpacing: 0.08em
  label-meta:
    fontFamily: Work Sans
    fontSize: 12px
    fontWeight: '500'
    lineHeight: 16px
  data-telemetry:
    fontFamily: Space Grotesk
    fontSize: 13px
    fontWeight: '600'
    lineHeight: 16px
    letterSpacing: 0.02em
rounded:
  sm: 0.125rem
  DEFAULT: 0.25rem
  md: 0.375rem
  lg: 0.5rem
  xl: 0.75rem
  full: 9999px
spacing:
  gutter: 1.5rem
  gutter-mobile: 0.75rem
  margin: 2rem
  margin-mobile: 1rem
  space-xs: 0.25rem
  space-sm: 0.5rem
  space-md: 1rem
  space-lg: 1.5rem
  space-xl: 2.5rem
---

## Brand & Style

The design system embodies the precision, adrenaline, and editorial authority of elite motorsport journalism. Positioned at the intersection of technical excellence (F1 telemetry, aero sculpting, carbon composite engineering) and narrative sporting journalism (The Race, Motorsport.com, The Athletic), the aesthetic rejects dated mid-2000s skeuomorphism and heavy dark-mode clutter in favor of an airy, high-velocity, content-first layout.

The emotional goal is rapid immersion: sharp contrast, clean white backdrops that let high-resolution photography breathe, and aggressive scarlet accents that command urgency without causing visual fatigue. 

Key attributes:
- **Style Archetype:** Modern High-Performance Editorial. Light, hyper-legible, rhythmically structured with tight mechanical metadata and wide editorial margins.
- **Tone:** Authoritative, razor-sharp, kinetic, and objective.
- **Visual Rhythm:** Crisp horizontal rules echoing paddock pit boards, monospace telemetry badges, and high-impact typographic hierarchy.

## Colors

The palette is engineered around high optical contrast and rapid visual scanning:

- **Primary (`#E10600` - Racing Red):** The iconic high-energy hue reserved for live indicators, breaking news tags, active category states, primary interaction hovers, and telemetry pips.
- **Secondary (`#111418` - Carbon Black):** Used for primary typography, structural mastheads, footer substrates, and high-contrast section dividers. It provides authoritative mass without looking flat.
- **Tertiary (`#D32F2F` - Deep Crimson):** Used for hover-states of primary elements and active alert badges.
- **Neutral (`#6C757D` - Neutral Gray):** Powers article timestamps, author attribution, and secondary metadata against `#FFFFFF` or `#F9FAFB`.
- **Canvas Base (`#FFFFFF` and `#F9FAFB`):** Replaces legacy mud-dark layouts with pure editorial light, rendering car liveries, track photography, and typography with maximum clarity.

## Typography

The typographical engine combines the technical velocity of **Space Grotesk** with the balanced editorial legibility of **Work Sans**.

- **Space Grotesk** drives all display tiers, headlines, ticker updates, category tags, and lap times. Its angular, mechanical terminals mimic high-end technical instruments and racing numbers.
- **Work Sans** powers all sustained reading experiences, excerpts, article bodies, and metadata. Its wide apertures ensure zero reading fatigue across dense race recaps and analysis.
- **Case conventions:** Category tags and telemetry markers strictly use uppercase tracking (`label-category`), whereas article headlines retain standard sentence/title casing for rapid scanability.

## Layout & Spacing

The layout is built on a responsive 12-column grid capped at a maximum container width of `1280px` for optimal reading scan line-length.

- **Breakpoints:**
  - `Mobile (320px - 767px):` 4-column system, 16px outer margin, single-stack cards, sticky compact ticker.
  - `Tablet (768px - 1023px):` 8-column system, 24px outer margin, 2-column editorial flow.
  - `Desktop (1024px - 1280px+):` 12-column system, 32px outer margin, 24px gutter. Classic asymmetric splits (8 columns for lead stories & feeds, 4 columns for standings, live timing widgets, and editorial opinion).
- **Rhythm:** Spacing follows a functional 4px/8px module. Micro-spacing (`space-xs` and `space-sm`) separates tags, bylines, and timestamps, while macro-spacing (`space-lg` and `space-xl`) isolates discrete headline blocks and race dossiers.

## Elevation & Depth

To maintain a crisp, fast-loading, trackside-engineered aesthetic, elevation avoids fuzzy, heavy drop shadows:

- **Ghost Borders & Crisp Rules:** Depth is primarily defined through hairline structural borders (`1px solid #E5E7EB`) and high-contrast tonal separation against `#FFFFFF` and `#F9FAFB` substrates.
- **Kinetic Hover Elevation:** Cards and interactive media blocks use an ultra-subtle, sharp ambient lift on interaction: `0 4px 12px -2px rgba(17, 20, 24, 0.06), 0 2px 4px -1px rgba(17, 20, 24, 0.04)`.
- **Telemetry & Live Overlay:** Semi-translucent glass surfaces (`rgba(17, 20, 24, 0.85)` with `backdrop-filter: blur(8px)`) are used exclusively on top of full-bleed hero media for telemetry chips, lap counts, and race session badges.

## Shapes

The system adopts a **Soft (Level 1)** geometric discipline. 

Aerodynamic precision demands sharp, clean boundaries rather than playful bubble pills:
- Base elements (cards, input frames, thumbnail containers, table blocks) use `0.25rem` (4px) corner radii.
- Secondary badges and category indicators use `2px` or `4px` to maintain a sharp, technical identification aesthetic.
- Buttons and action controls strictly avoid round pill radii, holding to `4px` chamfered precision that feels industrial and race-engineered.

## Components

### 1. Breaking News & Live Timing Ticker
- **Structure:** Slim bar anchored directly below the main navigation. 
- **Style:** Deep Carbon `#111418` background with an animated pulsing indicator (`#E10600`) for "AO VIVO" or "ÚLTIMA HORA". 
- **Typography:** `Space Grotesk` 13px bold for race series tags; `Work Sans` 13px for headlines and time deltas.

### 2. Category Badges & Pills
- **Style:** Compact badges with uppercase text (`label-category`).
- **Variants:**
  - *F1:* Neutral dark fill `#111418` with white text.
  - *Stock Car / NASCAR / Le Mans:* White surface with 1px border `#E5E7EB` and high-contrast text; hover switches border to `#E10600`.
  - *Live / Breaking:* High-visibility `#E10600` solid fill with white text.

### 3. Editorial Story Cards
- **Lead Hero Card:** Full-bleed 16:9 photography, subtle bottom vignette gradient, overlaid category badge, headline in `display-hero`, and metadata row (date, reading time, author).
- **Standard Feed Card:** Horizontal layout on desktop (1:1 or 4:3 thumbnail on the left, headline and excerpt on the right); vertical stack on mobile. Dividers between cards are 1px `#E5E7EB`.
- **Hover Micro-interaction:** Image undergoes a subtle 1.02x scale; headline shifts color from `#111418` to `#E10600` within 150ms.

### 4. Standings & Results Table
- **Header:** Sticky row with uppercase `Space Grotesk` 11px text and faint bottom rule.
- **Row Styling:** Zebra striping alternate with `#FFFFFF` and `#F9FAFB`. Driver position highlighted in bold; time interval rendered in mono-spaced digits.

### 5. Buttons & Interactive Controls
- **Primary Button:** `#E10600` fill, white uppercase bold text, 4px border radius, 0.75rem vertical padding. Hover transitions to `#D32F2F`.
- **Secondary Button:** Transparent fill, 1px `#111418` border, `#111418` text. Hover shifts to `#111418` fill with white text.
- **Newsletter / Search Inputs:** `#FFFFFF` background, 1px border `#E5E7EB`, 8px padding, focus ring with 1px `#E10600` outline.