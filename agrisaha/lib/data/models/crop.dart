import '../../core/utils/date_utils.dart';
import 'enums.dart';

/// Origine de la durée de maturation utilisée pour estimer la récolte.
enum EstimateSource {
  catalogVariety('Variété du catalogue'),
  userVariety('Durée saisie pour la variété'),
  generic('Estimation générique de la culture'),
  manual('Date saisie ou corrigée manuellement');

  const EstimateSource(this.label);
  final String label;
}

/// Culture (semis) suivie sur une parcelle.
class Crop {
  const Crop({
    required this.id,
    required this.plotId,
    required this.cropType,
    this.customCropName,
    this.varietyName,
    this.varietyRef,
    required this.seedType,
    required this.sowingDate,
    required this.area,
    required this.areaUnit,
    this.region,
    this.commune,
    this.village,
    this.soilType = SoilType.inconnu,
    required this.irrigationMode,
    required this.maturationDays,
    this.maturationMinDays,
    this.maturationMaxDays,
    required this.estimateSource,
    required this.estimatedHarvestDate,
    this.status = CropStatus.active,
    this.notes,
    this.photoPath,
    this.isDemo = false,
    required this.createdAt,
    required this.updatedAt,
  });

  final String id;
  final String plotId;
  final CropType cropType;

  /// Nom libre lorsque [cropType] vaut [CropType.autre].
  final String? customCropName;
  final String? varietyName;

  /// Identifiant de la variété (catalogue ou variété personnalisée).
  final String? varietyRef;
  final SeedType seedType;
  final DateTime sowingDate;
  final double area;
  final AreaUnit areaUnit;
  final String? region;
  final String? commune;
  final String? village;
  final SoilType soilType;
  final IrrigationMode irrigationMode;
  final int maturationDays;
  final int? maturationMinDays;
  final int? maturationMaxDays;
  final EstimateSource estimateSource;
  final DateTime estimatedHarvestDate;
  final CropStatus status;
  final String? notes;
  final String? photoPath;
  final bool isDemo;
  final DateTime createdAt;
  final DateTime updatedAt;

  String get displayName {
    final base =
        cropType == CropType.autre &&
            (customCropName?.trim().isNotEmpty ?? false)
        ? customCropName!.trim()
        : cropType.label;
    final variety = varietyName?.trim();
    return variety == null || variety.isEmpty ? base : '$base — $variety';
  }

  String get cropLabel =>
      cropType == CropType.autre && (customCropName?.trim().isNotEmpty ?? false)
      ? customCropName!.trim()
      : cropType.label;

  Crop copyWith({
    CropStatus? status,
    DateTime? estimatedHarvestDate,
    int? maturationDays,
    EstimateSource? estimateSource,
    String? notes,
    String? photoPath,
    DateTime? updatedAt,
  }) {
    return Crop(
      id: id,
      plotId: plotId,
      cropType: cropType,
      customCropName: customCropName,
      varietyName: varietyName,
      varietyRef: varietyRef,
      seedType: seedType,
      sowingDate: sowingDate,
      area: area,
      areaUnit: areaUnit,
      region: region,
      commune: commune,
      village: village,
      soilType: soilType,
      irrigationMode: irrigationMode,
      maturationDays: maturationDays ?? this.maturationDays,
      maturationMinDays: maturationMinDays,
      maturationMaxDays: maturationMaxDays,
      estimateSource: estimateSource ?? this.estimateSource,
      estimatedHarvestDate: estimatedHarvestDate ?? this.estimatedHarvestDate,
      status: status ?? this.status,
      notes: notes ?? this.notes,
      photoPath: photoPath ?? this.photoPath,
      isDemo: isDemo,
      createdAt: createdAt,
      updatedAt: updatedAt ?? this.updatedAt,
    );
  }

  Map<String, Object?> toMap() => {
    'id': id,
    'plot_id': plotId,
    'crop_type': cropType.name,
    'custom_crop_name': customCropName,
    'variety_name': varietyName,
    'variety_ref': varietyRef,
    'seed_type': seedType.name,
    'sowing_date': DateOnly.toIso(sowingDate),
    'area': area,
    'area_unit': areaUnit.name,
    'region': region,
    'commune': commune,
    'village': village,
    'soil_type': soilType.name,
    'irrigation_mode': irrigationMode.name,
    'maturation_days': maturationDays,
    'maturation_min_days': maturationMinDays,
    'maturation_max_days': maturationMaxDays,
    'estimate_source': estimateSource.name,
    'estimated_harvest_date': DateOnly.toIso(estimatedHarvestDate),
    'status': status.name,
    'notes': notes,
    'photo_path': photoPath,
    'is_demo': isDemo ? 1 : 0,
    'created_at': createdAt.toIso8601String(),
    'updated_at': updatedAt.toIso8601String(),
  };

  factory Crop.fromMap(Map<String, Object?> map) => Crop(
    id: map['id']! as String,
    plotId: map['plot_id']! as String,
    cropType: enumFromName(
      CropType.values,
      map['crop_type'] as String?,
      CropType.autre,
    ),
    customCropName: map['custom_crop_name'] as String?,
    varietyName: map['variety_name'] as String?,
    varietyRef: map['variety_ref'] as String?,
    seedType: enumFromName(
      SeedType.values,
      map['seed_type'] as String?,
      SeedType.autre,
    ),
    sowingDate: DateOnly.parse(map['sowing_date']! as String),
    area: (map['area']! as num).toDouble(),
    areaUnit: enumFromName(
      AreaUnit.values,
      map['area_unit'] as String?,
      AreaUnit.hectare,
    ),
    region: map['region'] as String?,
    commune: map['commune'] as String?,
    village: map['village'] as String?,
    soilType: enumFromName(
      SoilType.values,
      map['soil_type'] as String?,
      SoilType.inconnu,
    ),
    irrigationMode: enumFromName(
      IrrigationMode.values,
      map['irrigation_mode'] as String?,
      IrrigationMode.pluvial,
    ),
    maturationDays: (map['maturation_days']! as num).toInt(),
    maturationMinDays: (map['maturation_min_days'] as num?)?.toInt(),
    maturationMaxDays: (map['maturation_max_days'] as num?)?.toInt(),
    estimateSource: enumFromName(
      EstimateSource.values,
      map['estimate_source'] as String?,
      EstimateSource.generic,
    ),
    estimatedHarvestDate: DateOnly.parse(
      map['estimated_harvest_date']! as String,
    ),
    status: enumFromName(
      CropStatus.values,
      map['status'] as String?,
      CropStatus.active,
    ),
    notes: map['notes'] as String?,
    photoPath: map['photo_path'] as String?,
    isDemo: map['is_demo'] == 1,
    createdAt: DateTime.parse(map['created_at']! as String),
    updatedAt: DateTime.parse(map['updated_at']! as String),
  );
}
