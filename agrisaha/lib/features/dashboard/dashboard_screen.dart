import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/router.dart';
import '../../app/theme/app_theme.dart';
import '../../core/providers/providers.dart';
import '../../core/utils/formatters.dart';
import '../../domain/alert_builder.dart';
import '../../domain/crop_progress.dart';
import '../../domain/dashboard_calculator.dart';
import '../../shared/widgets/bar_chart.dart';
import '../../shared/widgets/common_widgets.dart';
import 'widgets/weather_status_card.dart';

class DashboardScreen extends ConsumerWidget {
  const DashboardScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final dashboard = ref.watch(dashboardProvider);
    final today = ref.watch(todayProvider);
    return Scaffold(
      appBar: AppBar(title: const Text('Tableau de bord')),
      body: AsyncValueView<DashboardStats>(
        value: dashboard,
        onRetry: () => ref.invalidate(dashboardProvider),
        data: (stats) {
          if (stats.isEmpty) {
            return EmptyState(
              icon: Icons.dashboard_outlined,
              title: 'Votre tableau de bord est vide',
              message:
                  'Les indicateurs sont calculés uniquement à partir de '
                  'vos données. Ajoutez une parcelle puis une culture pour '
                  'commencer.',
              actionLabel: 'Ajouter une parcelle',
              onAction: () => context.push(Routes.newPlot),
            );
          }
          return _DashboardBody(stats: stats, today: today);
        },
      ),
    );
  }
}

class _DashboardBody extends StatelessWidget {
  const _DashboardBody({required this.stats, required this.today});

  final DashboardStats stats;
  final DateTime today;

  @override
  Widget build(BuildContext context) {
    final finance = stats.finance;
    final next = stats.nextHarvestCrop;
    final nextRemaining = next == null
        ? null
        : CropProgress.compute(next, today).daysRemaining;
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        StatGrid(
          children: [
            StatTile(
              label: 'Parcelles enregistrées',
              value: '${stats.plotCount}',
              icon: Icons.crop_square,
            ),
            StatTile(
              label: 'Cultures en cours',
              value: '${stats.activeCropCount}',
              icon: Icons.grass,
            ),
            StatTile(
              label: 'Proches de la récolte (≤ 14 j)',
              value: '${stats.nearHarvestCount}',
              icon: Icons.agriculture,
              color: const Color(0xFF8D6E00),
              background: AppColors.lightYellow,
            ),
            StatTile(
              label: next == null
                  ? 'Prochaine récolte estimée'
                  : 'Prochaine récolte : ${next.cropLabel}',
              value: next == null
                  ? '—'
                  : Formatters.shortDate(next.estimatedHarvestDate),
              icon: Icons.event,
              color: const Color(0xFF8D6E00),
              background: AppColors.lightYellow,
            ),
            StatTile(
              label: 'Quantité totale récoltée',
              value:
                  '${Formatters.number(stats.totalHarvestKg, maxDecimals: 0)} kg',
              icon: Icons.inventory_2,
            ),
            StatTile(
              label: 'Alertes importantes',
              value: '${stats.importantAlertCount}',
              icon: Icons.warning_amber,
              color: AppColors.danger,
              background: AppColors.lightDanger,
            ),
          ],
        ),
        if (nextRemaining != null && nextRemaining < 0) ...[
          const SizedBox(height: 12),
          InfoBanner(
            message:
                'La date estimée de récolte de ${next!.displayName} '
                'est dépassée : vérifiez la maturité ou enregistrez la récolte.',
          ),
        ],
        if (stats.harvestsWithoutKg > 0) ...[
          const SizedBox(height: 12),
          InfoBanner(
            message:
                '${stats.harvestsWithoutKg} récolte(s) ne sont pas '
                'comptées en kg car le poids de l’unité n’a pas été indiqué.',
          ),
        ],
        const SizedBox(height: 16),
        SectionCard(
          title: 'Économie de l’exploitation',
          icon: Icons.account_balance_wallet,
          trailing: TextButton(
            onPressed: () => context.push(Routes.finances),
            child: const Text('Détails'),
          ),
          child: finance.isEmpty
              ? const Text('Aucune dépense ni recette enregistrée.')
              : Column(
                  children: [
                    HorizontalBarChart(
                      data: [
                        BarDatum(
                          label: 'Dépenses',
                          value: finance.totalExpenses,
                          valueLabel: Formatters.money(finance.totalExpenses),
                          color: AppColors.danger,
                        ),
                        BarDatum(
                          label: 'Recettes',
                          value: finance.totalIncomes,
                          valueLabel: Formatters.money(finance.totalIncomes),
                        ),
                      ],
                    ),
                    const Divider(height: 24),
                    InfoRow(
                      label: 'Bénéfice estimé (recettes − dépenses)',
                      value: Formatters.money(finance.margin),
                    ),
                  ],
                ),
        ),
        const SizedBox(height: 16),
        _PerformanceSection(performances: stats.performances),
        const SizedBox(height: 16),
        SectionCard(
          title: 'Alertes agricoles',
          icon: Icons.notifications_active,
          trailing: TextButton(
            onPressed: () => context.go(Routes.alerts),
            child: const Text('Toutes'),
          ),
          child: stats.alerts.isEmpty
              ? const Text('Aucune alerte pour le moment.')
              : Column(
                  children: [
                    for (final alert in stats.alerts.take(4))
                      ListTile(
                        contentPadding: EdgeInsets.zero,
                        leading: Icon(
                          alert.severity == AlertSeverity.high
                              ? Icons.error
                              : Icons.info,
                          color: alert.severity == AlertSeverity.high
                              ? AppColors.danger
                              : AppColors.harvestYellow,
                        ),
                        title: Text(alert.title),
                        subtitle: Text(alert.message),
                        onTap: () => context.push(Routes.crop(alert.cropId)),
                      ),
                  ],
                ),
        ),
        const SizedBox(height: 16),
        SectionCard(
          title: 'Travaux à effectuer aujourd’hui',
          icon: Icons.today,
          child: stats.todayTasks.isEmpty
              ? const Text('Aucun travail prévu aujourd’hui.')
              : Column(
                  children: [
                    for (final task in stats.todayTasks)
                      ListTile(
                        contentPadding: EdgeInsets.zero,
                        leading: const Icon(Icons.task_alt),
                        title: Text(task.title),
                        subtitle: Text(
                          '${stats.cropsById[task.cropId]?.displayName ?? ''} · '
                          'jusqu’au ${Formatters.shortDate(task.windowEndDate)}',
                        ),
                        onTap: () => context.push(Routes.crop(task.cropId)),
                      ),
                  ],
                ),
        ),
        const SizedBox(height: 16),
        SectionCard(
          title: 'Dernières activités',
          icon: Icons.history,
          child: stats.recentActivities.isEmpty
              ? const Text('Aucune activité enregistrée.')
              : Column(
                  children: [
                    for (final activity in stats.recentActivities)
                      ListTile(
                        contentPadding: EdgeInsets.zero,
                        leading: const Icon(
                          Icons.circle,
                          size: 12,
                          color: AppColors.green,
                        ),
                        title: Text(activity.title),
                        subtitle: Text(
                          '${Formatters.date(activity.date)} · ${activity.subtitle}',
                        ),
                        onTap: activity.cropId == null
                            ? null
                            : () => context.push(Routes.crop(activity.cropId!)),
                      ),
                  ],
                ),
        ),
        const SizedBox(height: 16),
        const WeatherStatusCard(),
      ],
    );
  }
}

class _PerformanceSection extends StatelessWidget {
  const _PerformanceSection({required this.performances});

  final List<CropPerformance> performances;

  @override
  Widget build(BuildContext context) {
    final harvested = performances.where((p) => p.harvestedKg > 0).toList();
    return SectionCard(
      title: 'Performances par culture',
      icon: Icons.bar_chart,
      child: performances.isEmpty
          ? const Text('Aucune culture enregistrée.')
          : Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                if (harvested.isEmpty)
                  const Text('Aucune récolte enregistrée en kg pour le moment.')
                else ...[
                  Text(
                    'Quantités récoltées',
                    style: Theme.of(context).textTheme.titleSmall,
                  ),
                  HorizontalBarChart(
                    data: [
                      for (final p in harvested)
                        BarDatum(
                          label: p.label,
                          value: p.harvestedKg,
                          valueLabel:
                              '${Formatters.number(p.harvestedKg, maxDecimals: 0)} kg',
                          color: AppColors.harvestYellow,
                        ),
                    ],
                  ),
                ],
                const Divider(height: 24),
                for (final p in performances)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 12),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          '${p.label} (${p.cropCount} culture(s))',
                          style: Theme.of(context).textTheme.titleSmall,
                        ),
                        InfoRow(
                          label: 'Rendement moyen',
                          value: p.yieldKgPerHa == null
                              ? 'Non calculable'
                              : '${Formatters.number(p.yieldKgPerHa!, maxDecimals: 0)} kg/ha',
                        ),
                        InfoRow(
                          label: 'Dépenses / recettes',
                          value:
                              '${Formatters.money(p.expenses)} / ${Formatters.money(p.incomes)}',
                        ),
                        InfoRow(
                          label: 'Marge',
                          value: Formatters.money(p.margin),
                        ),
                      ],
                    ),
                  ),
              ],
            ),
    );
  }
}
