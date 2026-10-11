import '../../core/utils/date_utils.dart';
import 'enums.dart';

/// Dépense ou recette de l'exploitation (montant en FCFA).
class FinanceEntry {
  const FinanceEntry({
    required this.id,
    required this.category,
    required this.amount,
    required this.entryDate,
    this.cropId,
    this.plotId,
    this.harvestId,
    this.description,
    this.isDemo = false,
    required this.createdAt,
    required this.updatedAt,
  });

  final String id;
  final FinanceCategory category;
  final double amount;
  final DateTime entryDate;
  final String? cropId;
  final String? plotId;

  /// Renseigné quand la recette provient d'une vente saisie avec la récolte.
  final String? harvestId;
  final String? description;
  final bool isDemo;
  final DateTime createdAt;
  final DateTime updatedAt;

  FinanceKind get kind => category.kind;

  Map<String, Object?> toMap() => {
    'id': id,
    'kind': category.kind.name,
    'category': category.name,
    'amount': amount,
    'entry_date': DateOnly.toIso(entryDate),
    'crop_id': cropId,
    'plot_id': plotId,
    'harvest_id': harvestId,
    'description': description,
    'is_demo': isDemo ? 1 : 0,
    'created_at': createdAt.toIso8601String(),
    'updated_at': updatedAt.toIso8601String(),
  };

  factory FinanceEntry.fromMap(Map<String, Object?> map) => FinanceEntry(
    id: map['id']! as String,
    category: enumFromName(
      FinanceCategory.values,
      map['category'] as String?,
      FinanceCategory.autreDepense,
    ),
    amount: (map['amount']! as num).toDouble(),
    entryDate: DateOnly.parse(map['entry_date']! as String),
    cropId: map['crop_id'] as String?,
    plotId: map['plot_id'] as String?,
    harvestId: map['harvest_id'] as String?,
    description: map['description'] as String?,
    isDemo: map['is_demo'] == 1,
    createdAt: DateTime.parse(map['created_at']! as String),
    updatedAt: DateTime.parse(map['updated_at']! as String),
  );
}
