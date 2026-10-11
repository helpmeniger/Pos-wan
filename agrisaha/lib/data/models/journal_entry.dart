import '../../core/utils/date_utils.dart';
import 'enums.dart';

/// Entrée du journal de suivi d'une culture.
class JournalEntry {
  const JournalEntry({
    required this.id,
    required this.cropId,
    required this.entryDate,
    required this.type,
    required this.title,
    this.details,
    this.quantity,
    this.quantityUnit,
    this.photoPath,
    this.isDemo = false,
    required this.createdAt,
    required this.updatedAt,
  });

  final String id;
  final String cropId;
  final DateTime entryDate;
  final JournalEntryType type;
  final String title;
  final String? details;

  /// Quantité éventuelle (eau, engrais…) avec son unité libre.
  final double? quantity;
  final String? quantityUnit;
  final String? photoPath;
  final bool isDemo;
  final DateTime createdAt;
  final DateTime updatedAt;

  Map<String, Object?> toMap() => {
    'id': id,
    'crop_id': cropId,
    'entry_date': DateOnly.toIso(entryDate),
    'entry_type': type.name,
    'title': title,
    'details': details,
    'quantity': quantity,
    'quantity_unit': quantityUnit,
    'photo_path': photoPath,
    'is_demo': isDemo ? 1 : 0,
    'created_at': createdAt.toIso8601String(),
    'updated_at': updatedAt.toIso8601String(),
  };

  factory JournalEntry.fromMap(Map<String, Object?> map) => JournalEntry(
    id: map['id']! as String,
    cropId: map['crop_id']! as String,
    entryDate: DateOnly.parse(map['entry_date']! as String),
    type: enumFromName(
      JournalEntryType.values,
      map['entry_type'] as String?,
      JournalEntryType.observation,
    ),
    title: map['title']! as String,
    details: map['details'] as String?,
    quantity: (map['quantity'] as num?)?.toDouble(),
    quantityUnit: map['quantity_unit'] as String?,
    photoPath: map['photo_path'] as String?,
    isDemo: map['is_demo'] == 1,
    createdAt: DateTime.parse(map['created_at']! as String),
    updatedAt: DateTime.parse(map['updated_at']! as String),
  );
}
