import 'package:sqflite/sqflite.dart';

import '../../core/database/app_database.dart';
import '../../core/errors/app_exception.dart';
import '../../core/utils/date_utils.dart';
import '../models/crop_task.dart';
import '../models/enums.dart';
import 'repository_utils.dart';
import 'sync_outbox.dart';

class TaskRepository {
  TaskRepository(this._database, {Clock? clock})
    : _clock = clock ?? DateTime.now;

  final AppDatabase _database;
  final Clock _clock;

  static const entity = 'crop_tasks';

  Future<List<CropTask>> getForCrop(String cropId) async {
    final rows = await _database.db.query(
      'crop_tasks',
      where: 'crop_id = ?',
      whereArgs: [cropId],
      orderBy: 'due_date ASC, title ASC',
    );
    return rows.map(CropTask.fromMap).toList();
  }

  Future<List<CropTask>> getAll() async {
    final rows = await _database.db.query(
      'crop_tasks',
      orderBy: 'due_date ASC',
    );
    return rows.map(CropTask.fromMap).toList();
  }

  /// Confirme la réalisation d'une tâche.
  Future<CropTask> markDone(String taskId, {String? note}) {
    return _update(taskId, (task, now) {
      return task.copyWith(
        status: TaskStatus.done,
        completedAt: now,
        note: cleanText(note) ?? task.note,
        updatedAt: now,
      );
    });
  }

  /// Reporte la tâche à une nouvelle date en conservant la durée de sa
  /// période recommandée.
  Future<CropTask> postpone(String taskId, DateTime newDate, {String? note}) {
    return _update(taskId, (task, now) {
      final today = DateOnly.today(now);
      if (DateOnly.of(newDate).isBefore(today)) {
        throw const ValidationException(
          'La nouvelle date ne peut pas être dans le passé.',
        );
      }
      final length = DateOnly.daysBetween(task.dueDate, task.windowEndDate);
      return task.copyWith(
        status: TaskStatus.postponed,
        dueDate: DateOnly.of(newDate),
        windowEndDate: DateOnly.addDays(newDate, length),
        note: cleanText(note) ?? task.note,
        clearCompletedAt: true,
        updatedAt: now,
      );
    });
  }

  /// Annule une confirmation (retour à « à faire »).
  Future<CropTask> reopen(String taskId) {
    return _update(taskId, (task, now) {
      return task.copyWith(
        status: TaskStatus.todo,
        clearCompletedAt: true,
        updatedAt: now,
      );
    });
  }

  Future<CropTask> updateNote(String taskId, String note) {
    return _update(taskId, (task, now) {
      return CropTask.fromMap({
        ...task.toMap(),
        'note': cleanText(note),
        'updated_at': now.toIso8601String(),
      });
    });
  }

  /// Marque comme effectuées les tâches « Récolte » encore en attente.
  static Future<void> completeHarvestTasks(
    DatabaseExecutor db,
    String cropId,
    DateTime now,
  ) async {
    await db.update(
      'crop_tasks',
      {
        'status': TaskStatus.done.name,
        'completed_at': now.toIso8601String(),
        'updated_at': now.toIso8601String(),
      },
      where: "crop_id = ? AND template_key = 'harvest' AND status IN (?, ?)",
      whereArgs: [cropId, TaskStatus.todo.name, TaskStatus.postponed.name],
    );
  }

  Future<CropTask> _update(
    String taskId,
    CropTask Function(CropTask task, DateTime now) change,
  ) async {
    late CropTask updated;
    await _database.db.transaction((txn) async {
      final rows = await txn.query(
        'crop_tasks',
        where: 'id = ?',
        whereArgs: [taskId],
        limit: 1,
      );
      if (rows.isEmpty) {
        throw const NotFoundException('Cette tâche n’existe plus.');
      }
      updated = change(CropTask.fromMap(rows.first), _clock());
      await txn.update(
        'crop_tasks',
        updated.toMap(),
        where: 'id = ?',
        whereArgs: [taskId],
      );
      await SyncOutbox.record(
        txn,
        entity: entity,
        entityId: taskId,
        operation: 'upsert',
        isDemo: updated.isDemo,
      );
    });
    return updated;
  }
}
