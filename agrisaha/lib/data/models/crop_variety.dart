import 'enums.dart';

/// Niveau de validation d'une donnée agronomique du catalogue.
enum ValidationStatus {
  toValidate('À valider par un technicien / l’INRAN'),
  validated('Validée'),
  userProvided('Saisie par l’utilisateur');

  const ValidationStatus(this.label);
  final String label;
}

/// Variété de semence avec sa durée de maturation (semis → récolte).
///
/// Les variétés proviennent soit du catalogue intégré
/// (`lib/data/catalog/crop_catalog.dart`), soit d'une saisie de
/// l'agriculteur ou du conseiller (table `crop_varieties`).
class CropVariety {
  const CropVariety({
    required this.id,
    required this.cropType,
    required this.name,
    required this.cycleMinDays,
    required this.cycleMaxDays,
    required this.typicalCycleDays,
    required this.source,
    required this.validationStatus,
    this.notes,
    this.isUserDefined = false,
  });

  final String id;
  final CropType cropType;
  final String name;
  final int cycleMinDays;
  final int cycleMaxDays;
  final int typicalCycleDays;

  /// Référence documentaire de la durée.
  final String source;
  final ValidationStatus validationStatus;
  final String? notes;
  final bool isUserDefined;

  Map<String, Object?> toMap(DateTime now) => {
    'id': id,
    'crop_type': cropType.name,
    'name': name,
    'cycle_min_days': cycleMinDays,
    'cycle_max_days': cycleMaxDays,
    'typical_cycle_days': typicalCycleDays,
    'source': source,
    'notes': notes,
    'created_at': now.toIso8601String(),
    'updated_at': now.toIso8601String(),
  };

  factory CropVariety.fromMap(Map<String, Object?> map) => CropVariety(
    id: map['id']! as String,
    cropType: enumFromName(
      CropType.values,
      map['crop_type'] as String?,
      CropType.autre,
    ),
    name: map['name']! as String,
    cycleMinDays: (map['cycle_min_days']! as num).toInt(),
    cycleMaxDays: (map['cycle_max_days']! as num).toInt(),
    typicalCycleDays: (map['typical_cycle_days']! as num).toInt(),
    source: (map['source'] as String?) ?? 'Saisie utilisateur',
    validationStatus: ValidationStatus.userProvided,
    notes: map['notes'] as String?,
    isUserDefined: true,
  );
}
