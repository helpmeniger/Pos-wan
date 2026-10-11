/// Migrations du schéma SQLite.
///
/// Règle : ne jamais modifier une migration déjà publiée. Pour faire évoluer
/// le schéma, ajouter une nouvelle entrée à [migrations] ; la version de la
/// base est égale au nombre de migrations. Les données existantes sont
/// conservées car seules les migrations manquantes sont exécutées.
library;

const List<List<String>> migrations = [_v1];

int get schemaVersion => migrations.length;

const _v1 = <String>[
  '''
  CREATE TABLE app_settings (
    key TEXT PRIMARY KEY NOT NULL,
    value TEXT
  )''',
  '''
  CREATE TABLE farm_profile (
    id TEXT PRIMARY KEY NOT NULL,
    display_name TEXT NOT NULL,
    farm_name TEXT,
    region TEXT,
    commune TEXT,
    village TEXT,
    language TEXT NOT NULL DEFAULT 'fr',
    crop_types TEXT,
    total_area REAL CHECK (total_area IS NULL OR total_area > 0),
    total_area_unit TEXT NOT NULL DEFAULT 'hectare',
    production_mode TEXT,
    local_area_unit_label TEXT,
    local_area_unit_hectares REAL
      CHECK (local_area_unit_hectares IS NULL OR local_area_unit_hectares > 0),
    notifications_enabled INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
  )''',
  '''
  CREATE TABLE plots (
    id TEXT PRIMARY KEY NOT NULL,
    name TEXT NOT NULL,
    area REAL NOT NULL CHECK (area > 0),
    area_unit TEXT NOT NULL,
    region TEXT,
    commune TEXT,
    village TEXT,
    soil_type TEXT NOT NULL DEFAULT 'inconnu',
    notes TEXT,
    is_demo INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
  )''',
  '''
  CREATE TABLE crop_varieties (
    id TEXT PRIMARY KEY NOT NULL,
    crop_type TEXT NOT NULL,
    name TEXT NOT NULL,
    cycle_min_days INTEGER NOT NULL CHECK (cycle_min_days > 0),
    cycle_max_days INTEGER NOT NULL CHECK (cycle_max_days >= cycle_min_days),
    typical_cycle_days INTEGER NOT NULL,
    source TEXT,
    notes TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (crop_type, name)
  )''',
  '''
  CREATE TABLE crops (
    id TEXT PRIMARY KEY NOT NULL,
    plot_id TEXT NOT NULL REFERENCES plots(id) ON DELETE RESTRICT,
    crop_type TEXT NOT NULL,
    custom_crop_name TEXT,
    variety_name TEXT,
    variety_ref TEXT,
    seed_type TEXT NOT NULL,
    sowing_date TEXT NOT NULL,
    area REAL NOT NULL CHECK (area > 0),
    area_unit TEXT NOT NULL,
    region TEXT,
    commune TEXT,
    village TEXT,
    soil_type TEXT NOT NULL DEFAULT 'inconnu',
    irrigation_mode TEXT NOT NULL,
    maturation_days INTEGER NOT NULL CHECK (maturation_days > 0),
    maturation_min_days INTEGER,
    maturation_max_days INTEGER,
    estimate_source TEXT NOT NULL,
    estimated_harvest_date TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'active',
    notes TEXT,
    photo_path TEXT,
    is_demo INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
  )''',
  'CREATE INDEX idx_crops_plot ON crops(plot_id)',
  'CREATE INDEX idx_crops_status ON crops(status, estimated_harvest_date)',
  '''
  CREATE TABLE crop_tasks (
    id TEXT PRIMARY KEY NOT NULL,
    crop_id TEXT NOT NULL REFERENCES crops(id) ON DELETE CASCADE,
    template_key TEXT NOT NULL,
    title TEXT NOT NULL,
    description TEXT,
    anchor TEXT NOT NULL,
    offset_start_days INTEGER NOT NULL,
    offset_end_days INTEGER NOT NULL,
    due_date TEXT NOT NULL,
    window_end_date TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'todo',
    completed_at TEXT,
    note TEXT,
    is_demo INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
  )''',
  'CREATE INDEX idx_tasks_crop ON crop_tasks(crop_id, due_date)',
  'CREATE INDEX idx_tasks_status ON crop_tasks(status, due_date)',
  '''
  CREATE TABLE journal_entries (
    id TEXT PRIMARY KEY NOT NULL,
    crop_id TEXT NOT NULL REFERENCES crops(id) ON DELETE CASCADE,
    entry_date TEXT NOT NULL,
    entry_type TEXT NOT NULL,
    title TEXT NOT NULL,
    details TEXT,
    quantity REAL CHECK (quantity IS NULL OR quantity >= 0),
    quantity_unit TEXT,
    photo_path TEXT,
    is_demo INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
  )''',
  'CREATE INDEX idx_journal_crop ON journal_entries(crop_id, entry_date)',
  '''
  CREATE TABLE harvests (
    id TEXT PRIMARY KEY NOT NULL,
    crop_id TEXT NOT NULL REFERENCES crops(id) ON DELETE CASCADE,
    harvest_date TEXT NOT NULL,
    quantity REAL NOT NULL CHECK (quantity >= 0),
    unit TEXT NOT NULL,
    unit_label TEXT,
    kg_per_unit REAL CHECK (kg_per_unit IS NULL OR kg_per_unit > 0),
    harvested_area REAL CHECK (harvested_area IS NULL OR harvested_area > 0),
    harvested_area_unit TEXT,
    losses_quantity REAL CHECK (losses_quantity IS NULL OR losses_quantity >= 0),
    sold_quantity REAL CHECK (sold_quantity IS NULL OR sold_quantity >= 0),
    stored_quantity REAL CHECK (stored_quantity IS NULL OR stored_quantity >= 0),
    unit_price REAL CHECK (unit_price IS NULL OR unit_price >= 0),
    is_final INTEGER NOT NULL DEFAULT 1,
    estimated_harvest_date TEXT NOT NULL,
    estimated_maturation_days INTEGER NOT NULL,
    notes TEXT,
    is_demo INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
  )''',
  'CREATE INDEX idx_harvests_crop ON harvests(crop_id, harvest_date)',
  '''
  CREATE TABLE finance_entries (
    id TEXT PRIMARY KEY NOT NULL,
    kind TEXT NOT NULL CHECK (kind IN ('expense', 'income')),
    category TEXT NOT NULL,
    amount REAL NOT NULL CHECK (amount > 0),
    entry_date TEXT NOT NULL,
    crop_id TEXT REFERENCES crops(id) ON DELETE SET NULL,
    plot_id TEXT REFERENCES plots(id) ON DELETE SET NULL,
    harvest_id TEXT REFERENCES harvests(id) ON DELETE CASCADE,
    description TEXT,
    is_demo INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
  )''',
  'CREATE INDEX idx_finance_date ON finance_entries(entry_date)',
  'CREATE INDEX idx_finance_crop ON finance_entries(crop_id)',
  // File d'attente des modifications locales à synchroniser. La contrainte
  // UNIQUE garantit une seule opération en attente par enregistrement
  // (prévention des doublons).
  '''
  CREATE TABLE sync_outbox (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    entity TEXT NOT NULL,
    entity_id TEXT NOT NULL,
    operation TEXT NOT NULL CHECK (operation IN ('upsert', 'delete')),
    queued_at TEXT NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    last_error TEXT,
    UNIQUE (entity, entity_id)
  )''',
];
