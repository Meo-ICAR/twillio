# Graph Report - twillio  (2026-10-07)

## Corpus Check
- Corpus is ~10,439 words - fits in a single context window. You may not need a graph.

## Summary
- 176 nodes · 194 edges · 22 communities (7 shown, 15 thin omitted)
- Extraction: 100% EXTRACTED · 0% INFERRED · 0% AMBIGUOUS
- Token cost: 0 input · 0 output

## Community Hubs (Navigation)
- Composer Config
- WhatsApp Controller
- Frontend Tooling
- DB Migrations
- User & Seeding
- Cache/DB/Session Config
- Composer Scripts
- Dev Dependencies
- Composer Plugins
- App Service Provider
- Feature Tests
- Unit Tests

## God Nodes (most connected - your core abstractions)
1. `User` - 9 edges
2. `scripts` - 9 edges
3. `require-dev` - 8 edges
4. `WhatsAppController` - 7 edges
5. `AppServiceProvider` - 5 edges
6. `require` - 5 edges
7. `config` - 5 edges
8. `UserFactory` - 5 edges
9. `psr-4` - 4 edges
10. `DatabaseSeeder` - 4 edges

## Surprising Connections (you probably didn't know these)
- `WhatsAppController` --inherits--> `Controller`  [EXTRACTED]
  app/Http/Controllers/WhatsAppController.php → app/Http/Controllers/Controller.php
- `ExampleTest` --inherits--> `TestCase`  [EXTRACTED]
  tests/Feature/ExampleTest.php → tests/TestCase.php

## Import Cycles
- None detected.

## Communities (22 total, 15 thin omitted)

### Community 0 - "Composer Config"
Cohesion: 0.08
Nodes (24): autoload, autoload-dev, psr-4, psr-4, description, extra, laravel, keywords (+16 more)

### Community 1 - "WhatsApp Controller"
Cohesion: 0.12
Nodes (5): Controller, WhatsAppController, {closure#1}(), {closure#2}(), {closure#3}()

### Community 2 - "Frontend Tooling"
Cohesion: 0.10
Nodes (20): devDependencies, concurrently, laravel-vite-plugin, tailwindcss, @tailwindcss/vite, vite, optionalDependencies, @laravel/multiplex (+12 more)

### Community 3 - "DB Migrations"
Cohesion: 0.16
Nodes (8): {closure#1}(), {closure#2}(), {closure#3}(), {closure#1}(), {closure#2}(), {closure#1}(), {closure#2}(), {closure#3}()

### Community 6 - "Composer Scripts"
Cohesion: 0.22
Nodes (9): scripts, dev, post-autoload-dump, post-create-project-cmd, post-root-package-install, post-update-cmd, pre-package-uninstall, setup (+1 more)

### Community 7 - "Dev Dependencies"
Cohesion: 0.25
Nodes (8): require-dev, fakerphp/faker, laravel/pail, laravel/pao, laravel/pint, mockery/mockery, nunomaduro/collision, phpunit/phpunit

### Community 8 - "Composer Plugins"
Cohesion: 0.29
Nodes (7): pestphp/pest-plugin, php-http/discovery, config, allow-plugins, optimize-autoloader, preferred-install, sort-packages

## Knowledge Gaps
- **51 isolated node(s):** `$schema`, `name`, `type`, `description`, `keywords` (+46 more)
  These have ≤1 connection - possible missing edges. (Counts symbols only; 92 node(s) total have ≤1 connection when file, concept and rationale nodes are included.)
- **15 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `scripts` connect `Composer Scripts` to `Composer Config`?**
  _High betweenness centrality (0.023) - this node is a cross-community bridge._
- **What connects `$schema`, `name`, `type` to the rest of the system?**
  _51 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `Composer Config` be split into smaller, more focused modules?**
  _Cohesion score 0.08 - nodes in this community are weakly interconnected._
- **Why does `require-dev` connect `Dev Dependencies` to `Composer Config`?**
  _High betweenness centrality (0.020) - this node is a cross-community bridge._
- **Should `WhatsApp Controller` be split into smaller, more focused modules?**
  _Cohesion score 0.1225296442687747 - nodes in this community are weakly interconnected._
- **Why does `config` connect `Composer Plugins` to `Composer Config`?**
  _High betweenness centrality (0.017) - this node is a cross-community bridge._
- **Should `Frontend Tooling` be split into smaller, more focused modules?**
  _Cohesion score 0.09956709956709957 - nodes in this community are weakly interconnected._