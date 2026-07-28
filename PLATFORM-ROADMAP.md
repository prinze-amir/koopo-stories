# Koopo Long-Term Platform Roadmap

## Purpose

This document is the transition note for moving Koopo from a WordPress plugin-based product into a platform that can support large-scale social, media, commerce, and booking workloads.

The current plugin is appropriate for launch, learning, and early product validation. It is not the final architecture.

## Current Reality

Current hosting assumptions:

- Hostinger-hosted WordPress
- 200 PHP workers
- 200 max processes
- WordPress plugin architecture as the main runtime
- BuddyBoss and plugin hooks handling major social behavior

This can work for launch and early traction. It is not the right foundation for a product that aims to behave like a hybrid of Facebook, YouTube, Amazon, and a services marketplace.

The limits are structural:

- PHP worker concurrency is request-bound and expensive under heavy interactive traffic.
- WordPress couples business logic, rendering, auth, admin, and persistence too tightly.
- Plugin hooks make behavior harder to reason about as the product grows.
- MySQL becomes both the transactional store and the read-heavy social store.
- Media, analytics, feed generation, counters, and notifications are not naturally event-driven.
- Horizontal scale is possible only up to a point, and it becomes operationally inefficient.

## Long-Term Goal

Koopo should become a platform with:

- independently deployable application services
- API-first clients for web and mobile
- asynchronous media and event pipelines
- dedicated systems for search, analytics, caching, notifications, and recommendation workloads
- strong domain boundaries between social, commerce, booking, messaging, media, and identity

The target is not "make WordPress bigger." The target is "use WordPress to launch, then replace it with a platform you own."

## Strategic Direction

Use a strangler migration, not a rewrite-in-one-shot.

That means:

1. Keep WordPress running for launch and short-term revenue.
2. Stop treating WordPress as the permanent home of core business logic.
3. Introduce clean domain boundaries and external services beside WordPress.
4. Move high-scale workloads out first.
5. Move the product UI and API contracts to the new platform incrementally.
6. Retire WordPress once it is no longer the system of record for the core product.

## What Must Leave WordPress First

The first systems to extract should be the ones that are read-heavy, write-heavy, or media-heavy.

Priority order:

1. Media upload, processing, storage, and delivery
2. Stories/feed generation and story engagement events
3. Notifications and async jobs
4. Reactions, comments, counters, and analytics events
5. Social graph and privacy enforcement
6. Marketplace/catalog/orders/payments
7. Booking and scheduling workflows
8. Messaging and real-time activity

## Recommended Target Architecture

Do not jump straight into dozens of microservices. Start with a platform core and extract services where scale or domain complexity demands it.

Recommended shape:

- Client layer
  - Web app
  - Mobile apps
  - Admin/backoffice tools

- Edge layer
  - CDN
  - WAF/rate limiting
  - API gateway
  - signed upload endpoints

- Application layer
  - API/BFF layer for clients
  - modular core backend
  - async workers and event consumers

- Data and platform layer
  - transactional databases
  - cache/session/rate-limit store
  - object storage
  - search index
  - analytics warehouse
  - queue/event bus

## Strong Recommendation On Backend Shape

For Koopo's next stage, the best course is:

- build a modular monolith first, not a microservice zoo
- keep strict domain boundaries inside that monolith
- extract services only when scaling, team ownership, or failure isolation clearly requires it

Why:

- You are still evolving the product quickly.
- Premature microservices will slow feature delivery and add infrastructure burden.
- A modular monolith can handle a very large business if the boundaries are clean.
- Service extraction becomes much easier when module boundaries already exist.

If the team is strongest in JavaScript/TypeScript:

- use TypeScript on the backend
- prefer Fastify or NestJS with clean domain modules

If the team is stronger in backend systems engineering:

- Go is a strong option for high-concurrency APIs and workers

The key decision is less about language and more about architecture discipline:

- API contracts
- domain ownership
- async processing
- idempotent jobs
- observability

## Core Domain Breakdown

The platform should eventually separate into these domains:

### 1. Identity and Accounts

Owns:

- user accounts
- authentication
- sessions/tokens
- profile basics
- account state
- roles and permissions

Notes:

- This should become the source of truth for identity.
- WordPress user tables should not remain the long-term authority.

### 2. Social Graph

Owns:

- follows
- friends/connections
- blocks
- close friends
- visibility relationships

Notes:

- Privacy and graph queries should not live as scattered plugin logic.
- This domain becomes critical for feed eligibility and content access.

### 3. Content and Feed

Owns:

- posts
- stories
- feed candidates
- engagement counters
- ranking inputs
- moderation state

Notes:

- Stories and feed logic should become a dedicated bounded context.
- This is one of the first extraction targets.

### 4. Media Platform

Owns:

- image/video upload
- storage
- transcoding
- thumbnails
- moderation scans
- delivery URLs
- metadata about assets

Notes:

- Clients should upload directly to object storage using signed URLs.
- The application should not proxy large media files through PHP or app servers.

### 5. Messaging and Notifications

Owns:

- in-app notifications
- push notifications
- email triggers
- real-time message fanout
- activity events

### 6. Commerce and Marketplace

Owns:

- products
- services
- carts
- orders
- payouts
- disputes
- merchant profiles

### 7. Booking

Owns:

- appointment slots
- availability
- reservations
- scheduling conflicts
- booking state transitions

### 8. Search and Discovery

Owns:

- people search
- product search
- content search
- filters
- ranking rules

### 9. Analytics

Owns:

- events
- funnels
- creator analytics
- merchant analytics
- experimentation metrics

This should be append-heavy and decoupled from transactional APIs.

## Stories Module: Future Architecture

The current stories plugin should eventually become a dedicated platform module with these responsibilities:

- story creation API
- story item metadata
- privacy checks through the social graph service
- story read state
- story engagement events
- story archive state
- moderation hooks

It should stop owning these concerns directly:

- raw media file transfer
- video transformation
- thumbnail generation
- analytics aggregation
- notification delivery

### Future Stories Request Flow

1. Client requests a signed upload URL.
2. Client uploads image/video directly to object storage.
3. Media service emits processing jobs.
4. Worker generates thumbnails, video variants, metadata, and moderation results.
5. Stories service creates the story record only after the media asset is valid.
6. Feed service indexes or fans out the new story to eligible viewers.
7. Read state, reactions, replies, and view counts become async-friendly event flows with cached materialized counters.

### Future Stories Data Model

Separate:

- story record
- story item record
- media asset record
- viewer/read state
- reaction events
- reply records
- moderation records

Avoid storing derived counters as the only source of truth.

Use:

- append events for raw interactions
- materialized counters for fast reads

## Suggested Infrastructure Stack

Exact vendors can change, but the platform needs these capabilities:

- compute platform for APIs and workers
- managed Postgres or equivalent transactional database
- Redis-compatible cache
- object storage
- CDN
- queue/event backbone
- analytics warehouse
- search engine
- observability stack

Practical example shape:

- APIs: containerized services or managed runtime
- DB: managed Postgres
- Cache: Redis
- Media: S3-compatible object storage
- CDN: global edge CDN
- Queue: SQS/SNS, RabbitMQ, Kafka, or Redpanda depending maturity
- Search: OpenSearch or Elasticsearch
- Analytics: ClickHouse, BigQuery, or Snowflake depending budget and team
- Monitoring: logs, traces, metrics, alerting from day one

## Scalability Principles

These are non-negotiable if the goal is large concurrent usage.

### 1. Async First

Anything that does not need to finish during the request should move to a job or event.

Examples:

- media processing
- notifications
- analytics writes
- denormalized counter rebuilds
- feed fanout
- search indexing

### 2. Direct-to-Storage Media Uploads

Do not send large photos and videos through WordPress or future API servers once the new platform starts.

### 3. Separate OLTP From Analytics

Transactional databases should not also be the analytics engine.

### 4. Cache Aggressively, Invalidate Deliberately

Heavy read surfaces need caching:

- profile payloads
- story trays
- feed fragments
- product snippets
- availability snapshots

### 5. Build for Idempotency

Workers and event consumers must be safe to retry.

### 6. Prefer Materialized Read Models For Hot Paths

Do not rebuild expensive social and feed state on every request once traffic grows.

### 7. Measure Everything

You will not scale by intuition. You will scale by:

- p95 and p99 latency
- queue depth
- cache hit rate
- DB CPU and IOPS
- media processing lag
- event loss/retry rate
- cost per active user

## Migration Plan

### Phase 0: Launch and Stabilize

Goal:

- launch on WordPress
- gather real user behavior
- avoid unnecessary rewrites before product validation

Rules:

- keep plugin improvements tactical
- fix correctness, security, and obvious scaling issues
- do not expand plugin complexity without thinking about the future service boundary

### Phase 1: Create Platform Boundaries While Still On WordPress

Goal:

- stop deepening platform lock-in

Actions:

- define domain APIs for stories, users, graph, media, commerce, and booking
- isolate new logic behind service-like interfaces
- standardize event names and payloads
- move uploads to object storage if possible
- introduce queue-backed background processing
- centralize observability

Deliverable:

- WordPress becomes a client and orchestration layer, not the only backend

### Phase 2: Extract Media and Async Infrastructure

Goal:

- remove the heaviest operational burden from WordPress

Actions:

- build media service
- signed uploads
- transcode pipeline
- thumbnail pipeline
- moderation pipeline
- CDN-backed delivery

Deliverable:

- story, post, and product media no longer depend on PHP request workers

### Phase 3: Extract Social Core

Goal:

- move feed, stories, reactions, replies, and graph logic out of WordPress

Actions:

- build identity and graph models
- build stories/content APIs
- move read-state and engagement storage
- build feed read model
- emit events for notifications and analytics

Deliverable:

- WordPress stops being the system of record for the social product

### Phase 4: Extract Marketplace and Booking

Goal:

- move the highest-value business workflows onto owned infrastructure

Actions:

- product/service catalog service
- orders and payments
- merchant/creator management
- booking calendar/availability engine
- customer notifications and reminders

Deliverable:

- revenue-critical flows are no longer trapped inside plugins

### Phase 5: Replace The Frontend Shell

Goal:

- remove dependence on WordPress rendering

Actions:

- build dedicated web frontend
- build mobile apps
- move admin tools to dedicated internal apps
- keep WordPress only if still useful as a content CMS

Deliverable:

- WordPress becomes optional, not foundational

### Phase 6: Full Platform Ownership

Goal:

- own the application stack end to end

At this point:

- WordPress is retired or reduced to marketing/content only
- the product runs on the new platform
- domain services are independently scalable
- data models are owned by the platform team

## What Not To Do

Do not:

- keep adding major product domains as WordPress plugins forever
- use WordPress as the long-term system for high-frequency counters and interactions
- proxy large media through app servers
- mix transactional logic and analytics in the same hot-path database queries
- split into too many services too early
- postpone observability until after growth

## Immediate Post-Launch Priorities

As soon as the product launches, start these tracks in parallel:

1. Platform architecture design
2. Data model mapping from WordPress to platform-owned schemas
3. Event taxonomy
4. Media pipeline design
5. Identity and social graph design
6. Feed and stories service design
7. Cost model and traffic model
8. Build-vs-buy decisions for search, analytics, messaging, and notifications

## Suggested First Technical Milestones

These are the best next moves after launch:

### Milestone 1

Produce a full system map:

- current WordPress tables
- plugin-owned entities
- BuddyBoss dependencies
- media paths
- cron jobs
- external integrations

### Milestone 2

Define future platform domains and API contracts.

### Milestone 3

Stand up the first non-WordPress service:

- preferably media or stories

### Milestone 4

Introduce event-driven processing and centralized observability.

### Milestone 5

Move the most expensive workloads off WordPress.

## Final Position

WordPress should be treated as the launch vehicle, not the destination.

The right long-term move is:

- launch fast on the current system
- learn from real usage
- immediately begin a disciplined strangler migration
- build a modular platform core
- extract media, social, feed, analytics, commerce, and booking into owned infrastructure

If Koopo succeeds, the winning architecture will be a product platform with dedicated services and asynchronous pipelines, not a bigger plugin stack on a larger PHP box.
