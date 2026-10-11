import 'enums.dart';

/// Profil de l'agriculteur et de son exploitation (stocké localement).
class FarmProfile {
  const FarmProfile({
    required this.id,
    required this.displayName,
    this.farmName,
    this.region,
    this.commune,
    this.village,
    this.language = AppLanguage.fr,
    this.cropTypes = const [],
    this.totalArea,
    this.totalAreaUnit = AreaUnit.hectare,
    this.productionMode,
    this.localAreaUnitLabel,
    this.localAreaUnitHectares,
    this.notificationsEnabled = true,
    required this.createdAt,
    required this.updatedAt,
  });

  final String id;

  /// Nom ou pseudonyme.
  final String displayName;
  final String? farmName;
  final String? region;
  final String? commune;
  final String? village;
  final AppLanguage language;
  final List<CropType> cropTypes;
  final double? totalArea;
  final AreaUnit totalAreaUnit;
  final ProductionMode? productionMode;

  /// Unité de surface locale configurable (nom et équivalence en hectares).
  final String? localAreaUnitLabel;
  final double? localAreaUnitHectares;
  final bool notificationsEnabled;
  final DateTime createdAt;
  final DateTime updatedAt;

  Map<String, Object?> toMap() => {
    'id': id,
    'display_name': displayName,
    'farm_name': farmName,
    'region': region,
    'commune': commune,
    'village': village,
    'language': language.name,
    'crop_types': cropTypes.map((c) => c.name).join(','),
    'total_area': totalArea,
    'total_area_unit': totalAreaUnit.name,
    'production_mode': productionMode?.name,
    'local_area_unit_label': localAreaUnitLabel,
    'local_area_unit_hectares': localAreaUnitHectares,
    'notifications_enabled': notificationsEnabled ? 1 : 0,
    'created_at': createdAt.toIso8601String(),
    'updated_at': updatedAt.toIso8601String(),
  };

  factory FarmProfile.fromMap(Map<String, Object?> map) {
    final cropTypesRaw = (map['crop_types'] as String?) ?? '';
    return FarmProfile(
      id: map['id']! as String,
      displayName: map['display_name']! as String,
      farmName: map['farm_name'] as String?,
      region: map['region'] as String?,
      commune: map['commune'] as String?,
      village: map['village'] as String?,
      language: enumFromName(
        AppLanguage.values,
        map['language'] as String?,
        AppLanguage.fr,
      ),
      cropTypes: cropTypesRaw
          .split(',')
          .where((s) => s.isNotEmpty)
          .map((s) => enumFromName(CropType.values, s, CropType.autre))
          .toSet()
          .toList(),
      totalArea: (map['total_area'] as num?)?.toDouble(),
      totalAreaUnit: enumFromName(
        AreaUnit.values,
        map['total_area_unit'] as String?,
        AreaUnit.hectare,
      ),
      productionMode: map['production_mode'] == null
          ? null
          : enumFromName(
              ProductionMode.values,
              map['production_mode'] as String?,
              ProductionMode.pluvial,
            ),
      localAreaUnitLabel: map['local_area_unit_label'] as String?,
      localAreaUnitHectares: (map['local_area_unit_hectares'] as num?)
          ?.toDouble(),
      notificationsEnabled: map['notifications_enabled'] != 0,
      createdAt: DateTime.parse(map['created_at']! as String),
      updatedAt: DateTime.parse(map['updated_at']! as String),
    );
  }
}
