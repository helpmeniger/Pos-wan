import '../core/utils/date_utils.dart';
import '../data/models/crop.dart';
import '../data/models/crop_task.dart';
import '../data/models/enums.dart';
import '../data/models/finance_entry.dart';
import '../data/models/harvest.dart';
import '../data/models/journal_entry.dart';
import '../data/models/plot.dart';
import 'alert_builder.dart';
import 'crop_progress.dart';
import 'finance_calculator.dart';
import 'yield_calculator.dart';

/// Performance agrégée par culture (ex. tout le mil de l'exploitation).
class CropPerformance {
  CropPerformance(this.label);

  final String label;
  int cropCount = 0;
  double harvestedKg = 0;
  double harvestedHectares = 0;
  double expenses = 0;
  double incomes = 0;

  double? get yieldKgPerHa => harvestedHectares > 0 && harvestedKg > 0
      ? harvestedKg / harvestedHectares
      : null;

  double get margin => incomes - expenses;
}

/// Activité récente affichée sur le tableau de bord.
class RecentActivity {
  const RecentActivity({
    required this.date,
    required this.title,
    required this.subtitle,
    this.cropId,
  });

  final DateTime date;
  final String title;
  final String subtitle;
  final String? cropId;
}

class DashboardStats {
  const DashboardStats({
    required this.plotCount,
    required this.activeCropCount,
    required this.nearHarvestCount,
    required this.nextHarvestCrop,
    required this.alerts,
    required this.todayTasks,
    required this.recentActivities,
    required this.totalHarvestKg,
    required this.harvestsWithoutKg,
    required this.finance,
    required this.performances,
    required this.cropsById,
  });

  final int plotCount;
  final int activeCropCount;
  final int nearHarvestCount;

  /// Culture en cours dont la date estimée de récolte est la plus proche.
  final Crop? nextHarvestCrop;
  final List<FarmAlert> alerts;
  final List<CropTask> todayTasks;
  final List<RecentActivity> recentActivities;
  final double totalHarvestKg;

  /// Nombre de récoltes non convertibles en kg (unité sans poids).
  final int harvestsWithoutKg;
  final FinanceSummary finance;
  final List<CropPerformance> performances;
  final Map<String, Crop> cropsById;

  bool get isEmpty => plotCount == 0 && cropsById.isEmpty && finance.isEmpty;

  int get importantAlertCount =>
      alerts.where((a) => a.severity != AlertSeverity.info).length;
}

/// Calcule tous les indicateurs du tableau de bord à partir des données
/// réellement enregistrées (aucune valeur fictive).
class DashboardCalculator {
  const DashboardCalculator._();

  static DashboardStats compute({
    required List<Plot> plots,
    required List<Crop> crops,
    required List<CropTask> tasks,
    required List<Harvest> harvests,
    required List<FinanceEntry> financeEntries,
    required List<JournalEntry> journalEntries,
    required AreaConverter areaConverter,
    required DateTime today,
  }) {
    final day = DateOnly.of(today);
    final cropsById = {for (final c in crops) c.id: c};
    final active = crops.where((c) => c.status == CropStatus.active).toList();

    var nearHarvest = 0;
    Crop? next;
    for (final crop in active) {
      final remaining = CropProgress.compute(crop, day).daysRemaining;
      if (remaining >= 0 && remaining <= AlertBuilder.harvestSoonDays) {
        nearHarvest++;
      }
      if (next == null ||
          crop.estimatedHarvestDate.isBefore(next.estimatedHarvestDate)) {
        next = crop;
      }
    }

    final alerts = AlertBuilder.build(crops: crops, tasks: tasks, today: day);
    final todayTasks =
        tasks
            .where((t) => cropsById[t.cropId]?.status == CropStatus.active)
            .where((t) => t.isDueOn(day))
            .toList()
          ..sort((a, b) => a.windowEndDate.compareTo(b.windowEndDate));

    var totalKg = 0.0;
    var withoutKg = 0;
    final performances = <String, CropPerformance>{};
    CropPerformance perfFor(Crop crop) => performances.putIfAbsent(
      crop.cropLabel,
      () => CropPerformance(crop.cropLabel),
    );

    for (final crop in crops) {
      perfFor(crop).cropCount++;
    }
    for (final harvest in harvests) {
      final crop = cropsById[harvest.cropId];
      if (crop == null) continue;
      final result = YieldCalculator.compute(harvest, crop, areaConverter);
      if (result.quantityKg == null) {
        withoutKg++;
        continue;
      }
      totalKg += result.quantityKg!;
      final perf = perfFor(crop);
      perf.harvestedKg += result.quantityKg!;
      if (result.areaHectares != null) {
        perf.harvestedHectares += result.areaHectares!;
      }
    }
    for (final entry in financeEntries) {
      final crop = entry.cropId == null ? null : cropsById[entry.cropId];
      if (crop == null) continue;
      final perf = perfFor(crop);
      if (entry.kind == FinanceKind.expense) {
        perf.expenses += entry.amount;
      } else {
        perf.incomes += entry.amount;
      }
    }

    final activities =
        <RecentActivity>[
            for (final entry in journalEntries)
              RecentActivity(
                date: entry.entryDate,
                title: entry.title,
                subtitle:
                    '${entry.type.label} · ${cropsById[entry.cropId]?.displayName ?? ''}',
                cropId: entry.cropId,
              ),
            for (final task in tasks)
              if (task.status == TaskStatus.done &&
                  task.completedAt != null &&
                  task.templateKey != 'sowing')
                RecentActivity(
                  date: DateOnly.of(task.completedAt!),
                  title: task.title,
                  subtitle:
                      'Tâche effectuée · ${cropsById[task.cropId]?.displayName ?? ''}',
                  cropId: task.cropId,
                ),
            for (final harvest in harvests)
              RecentActivity(
                date: harvest.harvestDate,
                title: 'Récolte enregistrée',
                subtitle: cropsById[harvest.cropId]?.displayName ?? '',
                cropId: harvest.cropId,
              ),
            for (final crop in crops)
              RecentActivity(
                date: crop.sowingDate,
                title: 'Semis',
                subtitle: crop.displayName,
                cropId: crop.id,
              ),
          ]
          ..removeWhere((a) => a.date.isAfter(day))
          ..sort((a, b) => b.date.compareTo(a.date));

    final perfList = performances.values.toList()
      ..sort((a, b) => b.harvestedKg.compareTo(a.harvestedKg));

    return DashboardStats(
      plotCount: plots.length,
      activeCropCount: active.length,
      nearHarvestCount: nearHarvest,
      nextHarvestCrop: next,
      alerts: alerts,
      todayTasks: todayTasks,
      recentActivities: activities.take(6).toList(),
      totalHarvestKg: totalKg,
      harvestsWithoutKg: withoutKg,
      finance: FinanceCalculator.summarize(financeEntries),
      performances: perfList,
      cropsById: cropsById,
    );
  }
}
