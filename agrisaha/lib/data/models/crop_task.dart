import '../../core/utils/date_utils.dart';
import 'enums.dart';

/// Tâche du calendrier agricole d'une culture.
class CropTask {
  const CropTask({
    required this.id,
    required this.cropId,
    required this.templateKey,
    required this.title,
    required this.description,
    required this.anchor,
    required this.offsetStartDays,
    required this.offsetEndDays,
    required this.dueDate,
    required this.windowEndDate,
    this.status = TaskStatus.todo,
    this.completedAt,
    this.note,
    this.isDemo = false,
    required this.createdAt,
    required this.updatedAt,
  });

  final String id;
  final String cropId;
  final String templateKey;
  final String title;
  final String description;
  final TaskAnchor anchor;
  final int offsetStartDays;
  final int offsetEndDays;

  /// Début de la période recommandée.
  final DateTime dueDate;

  /// Fin de la période recommandée (incluse).
  final DateTime windowEndDate;
  final TaskStatus status;
  final DateTime? completedAt;
  final String? note;
  final bool isDemo;
  final DateTime createdAt;
  final DateTime updatedAt;

  bool isOverdue(DateTime today) =>
      status.isPending && DateOnly.of(windowEndDate).isBefore(today);

  /// La tâche est à réaliser aujourd'hui (aujourd'hui est dans sa période).
  bool isDueOn(DateTime today) =>
      status.isPending &&
      !DateOnly.of(dueDate).isAfter(today) &&
      !DateOnly.of(windowEndDate).isBefore(today);

  CropTask copyWith({
    TaskStatus? status,
    DateTime? dueDate,
    DateTime? windowEndDate,
    DateTime? completedAt,
    bool clearCompletedAt = false,
    String? note,
    DateTime? updatedAt,
  }) {
    return CropTask(
      id: id,
      cropId: cropId,
      templateKey: templateKey,
      title: title,
      description: description,
      anchor: anchor,
      offsetStartDays: offsetStartDays,
      offsetEndDays: offsetEndDays,
      dueDate: dueDate ?? this.dueDate,
      windowEndDate: windowEndDate ?? this.windowEndDate,
      status: status ?? this.status,
      completedAt: clearCompletedAt ? null : (completedAt ?? this.completedAt),
      note: note ?? this.note,
      isDemo: isDemo,
      createdAt: createdAt,
      updatedAt: updatedAt ?? this.updatedAt,
    );
  }

  Map<String, Object?> toMap() => {
    'id': id,
    'crop_id': cropId,
    'template_key': templateKey,
    'title': title,
    'description': description,
    'anchor': anchor.name,
    'offset_start_days': offsetStartDays,
    'offset_end_days': offsetEndDays,
    'due_date': DateOnly.toIso(dueDate),
    'window_end_date': DateOnly.toIso(windowEndDate),
    'status': status.name,
    'completed_at': completedAt?.toIso8601String(),
    'note': note,
    'is_demo': isDemo ? 1 : 0,
    'created_at': createdAt.toIso8601String(),
    'updated_at': updatedAt.toIso8601String(),
  };

  factory CropTask.fromMap(Map<String, Object?> map) => CropTask(
    id: map['id']! as String,
    cropId: map['crop_id']! as String,
    templateKey: map['template_key']! as String,
    title: map['title']! as String,
    description: (map['description'] as String?) ?? '',
    anchor: enumFromName(
      TaskAnchor.values,
      map['anchor'] as String?,
      TaskAnchor.sowing,
    ),
    offsetStartDays: (map['offset_start_days']! as num).toInt(),
    offsetEndDays: (map['offset_end_days']! as num).toInt(),
    dueDate: DateOnly.parse(map['due_date']! as String),
    windowEndDate: DateOnly.parse(map['window_end_date']! as String),
    status: enumFromName(
      TaskStatus.values,
      map['status'] as String?,
      TaskStatus.todo,
    ),
    completedAt: map['completed_at'] == null
        ? null
        : DateTime.parse(map['completed_at']! as String),
    note: map['note'] as String?,
    isDemo: map['is_demo'] == 1,
    createdAt: DateTime.parse(map['created_at']! as String),
    updatedAt: DateTime.parse(map['updated_at']! as String),
  );
}
