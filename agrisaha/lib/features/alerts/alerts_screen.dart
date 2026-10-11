import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/router.dart';
import '../../app/theme/app_theme.dart';
import '../../core/providers/providers.dart';
import '../../core/utils/formatters.dart';
import '../../domain/alert_builder.dart';
import '../../shared/widgets/common_widgets.dart';

/// Alertes calculées à partir des données locales (fonctionne hors
/// connexion).
class AlertsScreen extends ConsumerWidget {
  const AlertsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final alerts = ref.watch(alertsProvider);
    return Scaffold(
      appBar: AppBar(title: const Text('Alertes')),
      body: AsyncValueView<List<FarmAlert>>(
        value: alerts,
        onRetry: () => ref.invalidate(alertsProvider),
        data: (alerts) {
          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              const InfoBanner(
                message:
                    'Alertes établies à partir de votre calendrier et '
                    'des dates estimées de récolte. Les alertes météo et '
                    'sanitaires nécessitent une source de données qui n’est '
                    'pas encore configurée. Les notifications sur le téléphone '
                    'arriveront dans une prochaine version.',
              ),
              const SizedBox(height: 16),
              if (alerts.isEmpty)
                const EmptyState(
                  icon: Icons.notifications_none,
                  title: 'Aucune alerte',
                  message: 'Aucune tâche en retard ni récolte imminente.',
                )
              else
                for (final alert in alerts) ...[
                  _AlertTile(alert: alert),
                  const SizedBox(height: 10),
                ],
            ],
          );
        },
      ),
    );
  }
}

class _AlertTile extends StatelessWidget {
  const _AlertTile({required this.alert});

  final FarmAlert alert;

  @override
  Widget build(BuildContext context) {
    final (color, background, icon) = switch (alert.severity) {
      AlertSeverity.high => (
        AppColors.danger,
        AppColors.lightDanger,
        Icons.error,
      ),
      AlertSeverity.medium => (
        const Color(0xFF8D6E00),
        AppColors.lightYellow,
        Icons.warning_amber,
      ),
      AlertSeverity.info => (AppColors.green, AppColors.lightGreen, Icons.info),
    };
    return Card(
      color: background,
      child: ListTile(
        leading: Icon(icon, color: color),
        title: Text(
          alert.title,
          style: const TextStyle(fontWeight: FontWeight.w700),
        ),
        subtitle: Text(
          '${alert.kind.label} · ${Formatters.date(alert.date)}\n'
          '${alert.message}',
        ),
        isThreeLine: true,
        trailing: const Icon(Icons.chevron_right),
        onTap: () => context.push(Routes.crop(alert.cropId)),
      ),
    );
  }
}
