import '../core/utils/date_utils.dart';
import '../data/models/crop.dart';
import '../data/models/crop_task.dart';
import '../data/models/enums.dart';
import 'crop_progress.dart';

enum AlertSeverity { high, medium, info }

enum AlertKind {
  taskOverdue('Tâche en retard'),
  taskToday('À faire aujourd’hui'),
  taskUpcoming('Prochainement'),
  harvestSoon('Récolte proche'),
  harvestOverdue('Récolte estimée dépassée');

  const AlertKind(this.label);
  final String label;
}

/// Alerte calculée uniquement à partir des données enregistrées localement
/// (calendrier et dates estimées). Aucune alerte météo ou sanitaire n'est
/// produite sans source de données fiable.
class FarmAlert {
  const FarmAlert({
    required this.kind,
    required this.severity,
    required this.title,
    required this.message,
    required this.date,
    required this.cropId,
    this.taskId,
  });

  final AlertKind kind;
  final AlertSeverity severity;
  final String title;
  final String message;
  final DateTime date;
  final String cropId;
  final String? taskId;
}

class AlertBuilder {
  const AlertBuilder._();

  static const int harvestSoonDays = 14;
  static const int upcomingTaskDays = 7;

  static List<FarmAlert> build({
    required List<Crop> crops,
    required List<CropTask> tasks,
    required DateTime today,
  }) {
    final day = DateOnly.of(today);
    final activeCrops = {
      for (final crop in crops)
        if (crop.status == CropStatus.active) crop.id: crop,
    };
    final alerts = <FarmAlert>[];

    for (final task in tasks) {
      final crop = activeCrops[task.cropId];
      if (crop == null || !task.status.isPending) continue;
      if (task.isOverdue(day)) {
        final late = DateOnly.daysBetween(task.windowEndDate, day);
        alerts.add(
          FarmAlert(
            kind: AlertKind.taskOverdue,
            severity: AlertSeverity.high,
            title: task.title,
            message:
                '${crop.displayName} — en retard de '
                '$late jour${late > 1 ? 's' : ''}.',
            date: task.windowEndDate,
            cropId: crop.id,
            taskId: task.id,
          ),
        );
      } else if (task.isDueOn(day)) {
        alerts.add(
          FarmAlert(
            kind: AlertKind.taskToday,
            severity: AlertSeverity.medium,
            title: task.title,
            message: '${crop.displayName} — période en cours.',
            date: task.dueDate,
            cropId: crop.id,
            taskId: task.id,
          ),
        );
      } else {
        final inDays = DateOnly.daysBetween(day, task.dueDate);
        if (inDays > 0 && inDays <= upcomingTaskDays) {
          alerts.add(
            FarmAlert(
              kind: AlertKind.taskUpcoming,
              severity: AlertSeverity.info,
              title: task.title,
              message:
                  '${crop.displayName} — dans '
                  '$inDays jour${inDays > 1 ? 's' : ''}.',
              date: task.dueDate,
              cropId: crop.id,
              taskId: task.id,
            ),
          );
        }
      }
    }

    for (final crop in activeCrops.values) {
      final progress = CropProgress.compute(crop, day);
      if (progress.daysRemaining < 0) {
        alerts.add(
          FarmAlert(
            kind: AlertKind.harvestOverdue,
            severity: AlertSeverity.medium,
            title: 'Récolte estimée dépassée',
            message:
                '${crop.displayName} : la date estimée est passée depuis '
                '${progress.daysRemaining.abs()} jour(s). Vérifiez la maturité '
                'ou enregistrez la récolte.',
            date: crop.estimatedHarvestDate,
            cropId: crop.id,
          ),
        );
      } else if (progress.daysRemaining <= harvestSoonDays) {
        alerts.add(
          FarmAlert(
            kind: AlertKind.harvestSoon,
            severity: AlertSeverity.info,
            title: 'Récolte proche',
            message:
                '${crop.displayName} : récolte estimée dans '
                '${progress.daysRemaining} jour(s).',
            date: crop.estimatedHarvestDate,
            cropId: crop.id,
          ),
        );
      }
    }

    alerts.sort((a, b) {
      final bySeverity = a.severity.index.compareTo(b.severity.index);
      return bySeverity != 0 ? bySeverity : a.date.compareTo(b.date);
    });
    return alerts;
  }
}
