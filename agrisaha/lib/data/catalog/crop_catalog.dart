import '../models/crop_variety.dart';
import '../models/enums.dart';

/// Durée de cycle générique d'une culture, utilisée lorsque la variété est
/// inconnue. L'estimation est alors signalée comme « générique ».
class GenericCycle {
  const GenericCycle({
    required this.cropType,
    required this.minDays,
    required this.maxDays,
    required this.typicalDays,
    required this.note,
  });

  final CropType cropType;
  final int minDays;
  final int maxDays;
  final int typicalDays;
  final String note;
}

/// Catalogue agronomique intégré (consultable hors connexion).
///
/// IMPORTANT — statut de validation : les durées ci-dessous sont des plages
/// couramment publiées dans les fiches techniques sahéliennes. Elles sont
/// marquées « à valider » et doivent être confirmées par l'INRAN (Institut
/// National de la Recherche Agronomique du Niger) ou un service de
/// vulgarisation avant une diffusion à grande échelle. Voir
/// `docs/CATALOGUE_AGRONOMIQUE.md`. L'agriculteur ou le conseiller peut à
/// tout moment saisir sa propre variété ou corriger la date estimée.
class CropCatalog {
  const CropCatalog._();

  static const String genericSource =
      'Plage générique de cycle pour la culture au Sahel — à valider';

  static const List<GenericCycle> genericCycles = [
    GenericCycle(
      cropType: CropType.mil,
      minDays: 75,
      maxDays: 120,
      typicalDays: 90,
      note:
          'Les mils hâtifs font environ 75 à 95 jours, les mils tardifs '
          'peuvent dépasser 110 jours.',
    ),
    GenericCycle(
      cropType: CropType.sorgho,
      minDays: 90,
      maxDays: 130,
      typicalDays: 110,
      note:
          'Le cycle dépend fortement de la variété et de sa sensibilité '
          'à la longueur du jour.',
    ),
    GenericCycle(
      cropType: CropType.mais,
      minDays: 90,
      maxDays: 120,
      typicalDays: 105,
      note: 'Variétés précoces à intermédiaires.',
    ),
    GenericCycle(
      cropType: CropType.riz,
      minDays: 100,
      maxDays: 150,
      typicalDays: 120,
      note: 'Riz irrigué ou de bas-fond ; varie selon la variété et la saison.',
    ),
    GenericCycle(
      cropType: CropType.niebe,
      minDays: 60,
      maxDays: 90,
      typicalDays: 75,
      note: 'Les niébés hâtifs se récoltent vers 60–70 jours.',
    ),
    GenericCycle(
      cropType: CropType.arachide,
      minDays: 90,
      maxDays: 120,
      typicalDays: 105,
      note: 'Variétés hâtives (≈ 90 j) à semi-tardives (≈ 120 j).',
    ),
    GenericCycle(
      cropType: CropType.oignon,
      minDays: 90,
      maxDays: 130,
      typicalDays: 110,
      note: 'Durée comptée à partir du repiquage.',
    ),
    GenericCycle(
      cropType: CropType.tomate,
      minDays: 70,
      maxDays: 100,
      typicalDays: 85,
      note: 'Durée jusqu’à la première récolte, comptée à partir du repiquage.',
    ),
    GenericCycle(
      cropType: CropType.chou,
      minDays: 60,
      maxDays: 100,
      typicalDays: 80,
      note: 'Durée comptée à partir du repiquage.',
    ),
  ];

  /// Variétés nommées dont la durée de cycle est largement documentée.
  /// Liste volontairement courte : toute autre variété peut être ajoutée par
  /// l'utilisateur avec sa durée de cycle (source : agriculteur/conseiller).
  static const List<CropVariety> varieties = [
    CropVariety(
      id: 'catalog:mil:hkp',
      cropType: CropType.mil,
      name: 'HKP',
      cycleMinDays: 85,
      cycleMaxDays: 95,
      typicalCycleDays: 90,
      source: 'Fiche variétale INRAN (mil HKP, cycle ≈ 90 j) — à confirmer',
      validationStatus: ValidationStatus.toValidate,
    ),
    CropVariety(
      id: 'catalog:arachide:55-437',
      cropType: CropType.arachide,
      name: '55-437',
      cycleMinDays: 85,
      cycleMaxDays: 95,
      typicalCycleDays: 90,
      source:
          'Catalogue régional des espèces et variétés végétales '
          '(CEDEAO/UEMOA/CILSS) — arachide hâtive ≈ 90 j — à confirmer',
      validationStatus: ValidationStatus.toValidate,
    ),
    CropVariety(
      id: 'catalog:arachide:28-206',
      cropType: CropType.arachide,
      name: '28-206',
      cycleMinDays: 115,
      cycleMaxDays: 125,
      typicalCycleDays: 120,
      source:
          'Catalogue régional des espèces et variétés végétales '
          '(CEDEAO/UEMOA/CILSS) — arachide semi-tardive ≈ 120 j — à confirmer',
      validationStatus: ValidationStatus.toValidate,
    ),
  ];

  static GenericCycle? genericFor(CropType type) {
    for (final cycle in genericCycles) {
      if (cycle.cropType == type) return cycle;
    }
    return null;
  }

  static List<CropVariety> varietiesFor(CropType type) =>
      varieties.where((v) => v.cropType == type).toList();

  static CropVariety? varietyById(String id) {
    for (final variety in varieties) {
      if (variety.id == id) return variety;
    }
    return null;
  }
}
