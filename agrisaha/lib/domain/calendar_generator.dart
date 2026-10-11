import '../core/utils/date_utils.dart';
import '../core/utils/id_generator.dart';
import '../data/catalog/calendar_templates.dart';
import '../data/models/crop_task.dart';
import '../data/models/enums.dart';

/// Construit le calendrier des opérations d'une culture à partir des
/// modèles de tâches applicables à la culture et à son mode d'irrigation.
class CalendarGenerator {
  const CalendarGenerator._();

  static List<CropTask> generate({
    required String cropId,
    required CropType cropType,
    required IrrigationMode irrigationMode,
    required DateTime sowingDate,
    required DateTime estimatedHarvestDate,
    required DateTime now,
    bool isDemo = false,
    List<TaskTemplate> templates = CalendarTemplates.all,
    String Function() newId = IdGenerator.newId,
  }) {
    final today = DateOnly.of(now);
    final sowing = DateOnly.of(sowingDate);
    final harvest = DateOnly.of(estimatedHarvestDate);
    final tasks = <CropTask>[];

    CropTask build(TaskTemplate t, String key, int offsetStart, int offsetEnd) {
      final anchorDate = t.anchor == TaskAnchor.sowing ? sowing : harvest;
      final due = DateOnly.addDays(anchorDate, offsetStart);
      final end = DateOnly.addDays(anchorDate, offsetEnd);
      var status = TaskStatus.todo;
      DateTime? completedAt;
      if (t.key == 'sowing' && !due.isAfter(today)) {
        // Le semis déclaré à une date passée est, par définition, effectué.
        status = TaskStatus.done;
        completedAt = now;
      } else if (end.isBefore(today)) {
        status = TaskStatus.notTracked;
      }
      return CropTask(
        id: newId(),
        cropId: cropId,
        templateKey: key,
        title: t.title,
        description: t.description,
        anchor: t.anchor,
        offsetStartDays: offsetStart,
        offsetEndDays: offsetEnd,
        dueDate: due,
        windowEndDate: end,
        status: status,
        completedAt: completedAt,
        isDemo: isDemo,
        createdAt: now,
        updatedAt: now,
      );
    }

    for (final template in templates) {
      if (!template.appliesTo(cropType, irrigationMode)) continue;
      final every = template.repeatEveryDays;
      if (every == null) {
        final task = build(
          template,
          template.key,
          template.offsetStart,
          template.offsetEnd,
        );
        // Les tâches liées au semis ne doivent pas dépasser la récolte.
        if (template.anchor == TaskAnchor.sowing &&
            task.dueDate.isAfter(harvest)) {
          continue;
        }
        tasks.add(task);
        continue;
      }
      final limit = DateOnly.addDays(
        harvest,
        template.repeatUntilHarvestOffset ?? 0,
      );
      var occurrence = 0;
      var start = template.offsetStart;
      while (!DateOnly.addDays(sowing, start).isAfter(limit)) {
        occurrence++;
        final duration = template.offsetEnd - template.offsetStart;
        tasks.add(
          build(
            template,
            '${template.key}#$occurrence',
            start,
            start + duration,
          ),
        );
        start += every;
      }
    }

    tasks.sort((a, b) {
      final byDate = a.dueDate.compareTo(b.dueDate);
      return byDate != 0 ? byDate : a.title.compareTo(b.title);
    });
    return tasks;
  }

  /// Recalcule les dates des tâches liées à la récolte encore à faire
  /// lorsque la date estimée de récolte est corrigée.
  static List<CropTask> shiftHarvestAnchored(
    List<CropTask> tasks,
    DateTime newHarvestDate,
    DateTime now,
  ) {
    final harvest = DateOnly.of(newHarvestDate);
    return [
      for (final task in tasks)
        if (task.anchor == TaskAnchor.harvest && task.status == TaskStatus.todo)
          task.copyWith(
            dueDate: DateOnly.addDays(harvest, task.offsetStartDays),
            windowEndDate: DateOnly.addDays(harvest, task.offsetEndDays),
            updatedAt: now,
          ),
    ];
  }
}
