import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/app.dart';
import '../../app/router.dart';
import '../../app/theme/app_theme.dart';
import '../../core/providers/providers.dart';
import '../../core/utils/formatters.dart';
import '../../domain/dashboard_calculator.dart';
import '../../shared/widgets/common_widgets.dart';
import '../crop_tracking/widgets/crop_card.dart';
import '../dashboard/widgets/weather_status_card.dart';

class HomeScreen extends ConsumerWidget {
  const HomeScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final profile = ref.watch(profileProvider).value;
    final dashboard = ref.watch(dashboardProvider);
    final today = ref.watch(todayProvider);

    return Scaffold(
      body: SafeArea(
        child: RefreshIndicator(
          onRefresh: () async => ref.read(dataRevisionProvider.notifier).bump(),
          child: ListView(
            padding: const EdgeInsets.all(16),
            children: [
              _Header(name: profile?.displayName, today: today),
              const SizedBox(height: 16),
              if (profile == null) ...[
                SectionCard(
                  color: AppColors.lightGreen,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text(
                        'Vous pouvez utiliser AgriSaha sans compte. Créez un '
                        'profil (facultatif) pour indiquer votre région et vos '
                        'cultures.',
                      ),
                      const SizedBox(height: 12),
                      OutlinedButton.icon(
                        onPressed: () => context.push(Routes.editProfile),
                        icon: const Icon(Icons.person_add_alt),
                        label: const Text('Créer mon profil'),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 16),
              ],
              const _QuickActions(),
              const SizedBox(height: 16),
              AsyncValueView<DashboardStats>(
                value: dashboard,
                onRetry: () => ref.invalidate(dashboardProvider),
                data: (stats) => _HomeSummary(stats: stats, today: today),
              ),
              const SizedBox(height: 16),
              const WeatherStatusCard(),
            ],
          ),
        ),
      ),
    );
  }
}

class _Header extends StatelessWidget {
  const _Header({required this.name, required this.today});

  final String? name;
  final DateTime today;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Container(
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          colors: [AppColors.darkGreen, AppColors.green],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(22),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const CircleAvatar(
                backgroundColor: Colors.white,
                child: Icon(Icons.eco, color: AppColors.green),
              ),
              const SizedBox(width: 12),
              Text(
                AgriSahaApp.name,
                style: theme.textTheme.headlineSmall?.copyWith(
                  color: Colors.white,
                  fontWeight: FontWeight.w800,
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Text(
            AgriSahaApp.slogan,
            style: theme.textTheme.bodyMedium?.copyWith(
              color: Colors.white.withValues(alpha: 0.9),
            ),
          ),
          const SizedBox(height: 16),
          Text(
            name == null ? 'Bonjour !' : 'Bonjour, $name !',
            style: theme.textTheme.titleLarge?.copyWith(color: Colors.white),
          ),
          Text(
            Formatters.longDate(today),
            style: theme.textTheme.bodyMedium?.copyWith(
              color: AppColors.lightYellow,
            ),
          ),
        ],
      ),
    );
  }
}

class _QuickActions extends StatelessWidget {
  const _QuickActions();

  @override
  Widget build(BuildContext context) {
    final actions = [
      (Icons.add_circle, 'Nouvelle culture', Routes.newCrop),
      (Icons.crop_square, 'Mes parcelles', Routes.plots),
      (Icons.dashboard, 'Tableau de bord', Routes.dashboard),
      (Icons.account_balance_wallet, 'Finances', Routes.finances),
    ];
    return StatGrid(
      children: [
        for (final (icon, label, route) in actions)
          Card(
            clipBehavior: Clip.antiAlias,
            child: InkWell(
              onTap: () => context.push(route),
              child: Padding(
                padding: const EdgeInsets.symmetric(
                  vertical: 18,
                  horizontal: 12,
                ),
                child: Column(
                  children: [
                    Icon(icon, size: 32, color: AppColors.green),
                    const SizedBox(height: 8),
                    Text(
                      label,
                      textAlign: TextAlign.center,
                      style: Theme.of(context).textTheme.titleSmall,
                    ),
                  ],
                ),
              ),
            ),
          ),
      ],
    );
  }
}

class _HomeSummary extends StatelessWidget {
  const _HomeSummary({required this.stats, required this.today});

  final DashboardStats stats;
  final DateTime today;

  @override
  Widget build(BuildContext context) {
    if (stats.plotCount == 0) {
      return SectionCard(
        child: EmptyState(
          icon: Icons.crop_square,
          title: 'Commencez par votre première parcelle',
          message:
              'Enregistrez une parcelle, puis vos semis : AgriSaha '
              'calculera la date estimée de récolte et votre calendrier.',
          actionLabel: 'Ajouter une parcelle',
          onAction: () => context.push(Routes.newPlot),
        ),
      );
    }
    final next = stats.nextHarvestCrop;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        SectionCard(
          title: 'Travaux du jour',
          icon: Icons.today,
          trailing: TextButton(
            onPressed: () => context.go(Routes.alerts),
            child: const Text('Alertes'),
          ),
          child: stats.todayTasks.isEmpty
              ? const Text('Aucun travail prévu aujourd’hui.')
              : Column(
                  children: [
                    for (final task in stats.todayTasks.take(5))
                      ListTile(
                        contentPadding: EdgeInsets.zero,
                        leading: const Icon(
                          Icons.task_alt,
                          color: AppColors.harvestYellow,
                        ),
                        title: Text(task.title),
                        subtitle: Text(
                          stats.cropsById[task.cropId]?.displayName ?? '',
                        ),
                        trailing: const Icon(Icons.chevron_right),
                        onTap: () => context.push(Routes.crop(task.cropId)),
                      ),
                  ],
                ),
        ),
        const SizedBox(height: 16),
        StatGrid(
          children: [
            StatTile(
              label: 'Parcelles',
              value: '${stats.plotCount}',
              icon: Icons.crop_square,
            ),
            StatTile(
              label: 'Cultures en cours',
              value: '${stats.activeCropCount}',
              icon: Icons.grass,
            ),
            StatTile(
              label: 'Récoltes proches (14 j)',
              value: '${stats.nearHarvestCount}',
              icon: Icons.agriculture,
              color: const Color(0xFF8D6E00),
              background: AppColors.lightYellow,
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
        const SizedBox(height: 16),
        if (next != null)
          CropCard(
            crop: next,
            today: today,
            plotName: 'Prochaine récolte',
            onTap: () => context.push(Routes.crop(next.id)),
          )
        else
          SectionCard(
            child: EmptyState(
              icon: Icons.grass,
              title: 'Aucune culture en cours',
              message: 'Enregistrez un semis pour suivre sa croissance.',
              actionLabel: 'Nouvelle culture',
              onAction: () => context.push(Routes.newCrop),
            ),
          ),
        const SizedBox(height: 16),
        FilledButton.icon(
          onPressed: () => context.push(Routes.dashboard),
          icon: const Icon(Icons.dashboard),
          label: const Text('Voir le tableau de bord complet'),
        ),
      ],
    );
  }
}
