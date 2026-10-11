import '../../core/utils/date_utils.dart';
import 'enums.dart';

/// Récolte réellement effectuée sur une culture.
class Harvest {
  const Harvest({
    required this.id,
    required this.cropId,
    required this.harvestDate,
    required this.quantity,
    required this.unit,
    this.unitLabel,
    this.kgPerUnit,
    this.harvestedArea,
    this.harvestedAreaUnit,
    this.lossesQuantity,
    this.soldQuantity,
    this.storedQuantity,
    this.unitPrice,
    this.isFinal = true,
    required this.estimatedHarvestDate,
    required this.estimatedMaturationDays,
    this.notes,
    this.isDemo = false,
    required this.createdAt,
    required this.updatedAt,
  });

  final String id;
  final String cropId;
  final DateTime harvestDate;
  final double quantity;
  final HarvestUnit unit;

  /// Nom de l'unité lorsque [unit] vaut [HarvestUnit.autre] (ex. « tiya »).
  final String? unitLabel;

  /// Poids d'une unité en kg (sac, unité locale) pour calculer le rendement.
  final double? kgPerUnit;
  final double? harvestedArea;
  final AreaUnit? harvestedAreaUnit;

  /// Pertes estimées, dans la même unité que [quantity].
  final double? lossesQuantity;
  final double? soldQuantity;
  final double? storedQuantity;

  /// Prix de vente par unité, en FCFA.
  final double? unitPrice;

  /// Récolte terminée (dernière récolte de la culture).
  final bool isFinal;

  /// Copie de l'estimation au moment de la récolte, pour comparaison.
  final DateTime estimatedHarvestDate;
  final int estimatedMaturationDays;
  final String? notes;
  final bool isDemo;
  final DateTime createdAt;
  final DateTime updatedAt;

  String get unitSymbol =>
      unit == HarvestUnit.autre && (unitLabel?.trim().isNotEmpty ?? false)
      ? unitLabel!.trim()
      : unit.symbol;

  /// Recette de la vente déclarée (quantité vendue × prix unitaire).
  double? get salesRevenue => soldQuantity != null && unitPrice != null
      ? soldQuantity! * unitPrice!
      : null;

  Map<String, Object?> toMap() => {
    'id': id,
    'crop_id': cropId,
    'harvest_date': DateOnly.toIso(harvestDate),
    'quantity': quantity,
    'unit': unit.name,
    'unit_label': unitLabel,
    'kg_per_unit': kgPerUnit,
    'harvested_area': harvestedArea,
    'harvested_area_unit': harvestedAreaUnit?.name,
    'losses_quantity': lossesQuantity,
    'sold_quantity': soldQuantity,
    'stored_quantity': storedQuantity,
    'unit_price': unitPrice,
    'is_final': isFinal ? 1 : 0,
    'estimated_harvest_date': DateOnly.toIso(estimatedHarvestDate),
    'estimated_maturation_days': estimatedMaturationDays,
    'notes': notes,
    'is_demo': isDemo ? 1 : 0,
    'created_at': createdAt.toIso8601String(),
    'updated_at': updatedAt.toIso8601String(),
  };

  factory Harvest.fromMap(Map<String, Object?> map) => Harvest(
    id: map['id']! as String,
    cropId: map['crop_id']! as String,
    harvestDate: DateOnly.parse(map['harvest_date']! as String),
    quantity: (map['quantity']! as num).toDouble(),
    unit: enumFromName(
      HarvestUnit.values,
      map['unit'] as String?,
      HarvestUnit.kg,
    ),
    unitLabel: map['unit_label'] as String?,
    kgPerUnit: (map['kg_per_unit'] as num?)?.toDouble(),
    harvestedArea: (map['harvested_area'] as num?)?.toDouble(),
    harvestedAreaUnit: map['harvested_area_unit'] == null
        ? null
        : enumFromName(
            AreaUnit.values,
            map['harvested_area_unit'] as String?,
            AreaUnit.hectare,
          ),
    lossesQuantity: (map['losses_quantity'] as num?)?.toDouble(),
    soldQuantity: (map['sold_quantity'] as num?)?.toDouble(),
    storedQuantity: (map['stored_quantity'] as num?)?.toDouble(),
    unitPrice: (map['unit_price'] as num?)?.toDouble(),
    isFinal: map['is_final'] != 0,
    estimatedHarvestDate: DateOnly.parse(
      map['estimated_harvest_date']! as String,
    ),
    estimatedMaturationDays: (map['estimated_maturation_days']! as num).toInt(),
    notes: map['notes'] as String?,
    isDemo: map['is_demo'] == 1,
    createdAt: DateTime.parse(map['created_at']! as String),
    updatedAt: DateTime.parse(map['updated_at']! as String),
  );
}
