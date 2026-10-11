/// Énumérations métier partagées. Chaque valeur est stockée en base sous
/// son nom Dart (`name`) ; [enumFromName] gère les valeurs inconnues issues
/// d'anciennes versions.
library;

T enumFromName<T extends Enum>(List<T> values, String? name, T fallback) {
  if (name == null) return fallback;
  for (final value in values) {
    if (value.name == name) return value;
  }
  return fallback;
}

/// Familles de cultures utilisées pour adapter le calendrier agricole.
enum CropGroup { cereal, legume, rice, vegetable, other }

enum CropType {
  mil('Mil', CropGroup.cereal),
  sorgho('Sorgho', CropGroup.cereal),
  mais('Maïs', CropGroup.cereal),
  riz('Riz', CropGroup.rice),
  niebe('Niébé', CropGroup.legume),
  arachide('Arachide', CropGroup.legume),
  oignon('Oignon', CropGroup.vegetable),
  tomate('Tomate', CropGroup.vegetable),
  chou('Chou', CropGroup.vegetable),
  autre('Autre culture', CropGroup.other);

  const CropType(this.label, this.group);

  final String label;
  final CropGroup group;

  /// Cultures généralement repiquées : la « date de semis » saisie
  /// correspond alors à la date de repiquage.
  bool get isUsuallyTransplanted => group == CropGroup.vegetable;
}

enum SeedType {
  traditionnelle('Traditionnelle (locale)'),
  amelioree('Améliorée'),
  autre('Autre / inconnue');

  const SeedType(this.label);
  final String label;
}

enum IrrigationMode {
  pluvial('Pluvial'),
  irrigation('Irrigué'),
  mixte('Mixte');

  const IrrigationMode(this.label);
  final String label;

  bool get usesIrrigation => this != IrrigationMode.pluvial;
}

enum AreaUnit {
  hectare('Hectare (ha)', 'ha'),
  squareMeter('Mètre carré (m²)', 'm²'),
  local('Unité locale', 'u. locale');

  const AreaUnit(this.label, this.symbol);
  final String label;
  final String symbol;
}

enum SoilType {
  sableux('Sableux (dunaire)'),
  argileux('Argileux'),
  limoneux('Limoneux'),
  argiloSableux('Argilo-sableux'),
  lateritique('Latéritique / gravillonnaire'),
  basFond('Bas-fond / fadama'),
  inconnu('Non précisé');

  const SoilType(this.label);
  final String label;
}

enum CropStatus {
  active('En cours'),
  harvested('Récoltée'),
  abandoned('Abandonnée');

  const CropStatus(this.label);
  final String label;
}

enum TaskStatus {
  todo('À faire'),
  done('Effectuée'),
  postponed('Reportée'),

  /// Tâche dont la période était déjà passée lorsque la culture a été
  /// enregistrée : elle n'est pas comptée en retard.
  notTracked('Antérieure à l’enregistrement');

  const TaskStatus(this.label);
  final String label;

  bool get isPending => this == TaskStatus.todo || this == TaskStatus.postponed;
}

/// Point de référence d'une tâche du calendrier.
enum TaskAnchor { sowing, harvest }

enum HarvestUnit {
  kg('Kilogrammes (kg)', 'kg'),
  sac('Sacs', 'sac(s)'),
  tonne('Tonnes (t)', 't'),
  autre('Autre unité', 'unité(s)');

  const HarvestUnit(this.label, this.symbol);
  final String label;
  final String symbol;
}

enum JournalEntryType {
  observation('Observation'),
  work('Travaux réalisés'),
  irrigation('Irrigation / eau'),
  fertilization('Fertilisation'),
  treatment('Traitement'),
  problem('Problème constaté'),
  milestone('Date importante');

  const JournalEntryType(this.label);
  final String label;
}

enum FinanceKind {
  expense('Dépense'),
  income('Recette');

  const FinanceKind(this.label);
  final String label;
}

enum FinanceCategory {
  semences('Achat de semences', FinanceKind.expense),
  engrais('Achat d’engrais / fumure', FinanceKind.expense),
  mainOeuvre('Main-d’œuvre', FinanceKind.expense),
  transport('Transport', FinanceKind.expense),
  irrigation('Irrigation (carburant, eau…)', FinanceKind.expense),
  traitements('Traitements', FinanceKind.expense),
  autreDepense('Autre dépense', FinanceKind.expense),
  vente('Vente de récolte', FinanceKind.income),
  autreRecette('Autre recette', FinanceKind.income);

  const FinanceCategory(this.label, this.kind);
  final String label;
  final FinanceKind kind;

  static List<FinanceCategory> forKind(FinanceKind kind) =>
      values.where((c) => c.kind == kind).toList();
}

enum ProductionMode {
  pluvial('Cultures pluviales'),
  irrigue('Cultures irriguées / maraîchage'),
  mixte('Mixte');

  const ProductionMode(this.label);
  final String label;
}

enum AppLanguage {
  fr('Français'),
  ha('Haoussa (traduction à venir)'),
  dje('Zarma (traduction à venir)');

  const AppLanguage(this.label);
  final String label;
}
