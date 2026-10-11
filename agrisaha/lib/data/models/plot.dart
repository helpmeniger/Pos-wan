import 'enums.dart';

/// Parcelle cultivable de l'exploitation.
class Plot {
  const Plot({
    required this.id,
    required this.name,
    required this.area,
    required this.areaUnit,
    this.region,
    this.commune,
    this.village,
    this.soilType = SoilType.inconnu,
    this.notes,
    this.isDemo = false,
    required this.createdAt,
    required this.updatedAt,
  });

  final String id;
  final String name;
  final double area;
  final AreaUnit areaUnit;
  final String? region;
  final String? commune;
  final String? village;
  final SoilType soilType;
  final String? notes;
  final bool isDemo;
  final DateTime createdAt;
  final DateTime updatedAt;

  String get locationLabel => [
    village,
    commune,
    region,
  ].whereType<String>().where((s) => s.trim().isNotEmpty).join(', ');

  Plot copyWith({
    String? name,
    double? area,
    AreaUnit? areaUnit,
    String? region,
    String? commune,
    String? village,
    SoilType? soilType,
    String? notes,
    DateTime? updatedAt,
  }) {
    return Plot(
      id: id,
      name: name ?? this.name,
      area: area ?? this.area,
      areaUnit: areaUnit ?? this.areaUnit,
      region: region ?? this.region,
      commune: commune ?? this.commune,
      village: village ?? this.village,
      soilType: soilType ?? this.soilType,
      notes: notes ?? this.notes,
      isDemo: isDemo,
      createdAt: createdAt,
      updatedAt: updatedAt ?? this.updatedAt,
    );
  }

  Map<String, Object?> toMap() => {
    'id': id,
    'name': name,
    'area': area,
    'area_unit': areaUnit.name,
    'region': region,
    'commune': commune,
    'village': village,
    'soil_type': soilType.name,
    'notes': notes,
    'is_demo': isDemo ? 1 : 0,
    'created_at': createdAt.toIso8601String(),
    'updated_at': updatedAt.toIso8601String(),
  };

  factory Plot.fromMap(Map<String, Object?> map) => Plot(
    id: map['id']! as String,
    name: map['name']! as String,
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
    notes: map['notes'] as String?,
    isDemo: map['is_demo'] == 1,
    createdAt: DateTime.parse(map['created_at']! as String),
    updatedAt: DateTime.parse(map['updated_at']! as String),
  );
}
