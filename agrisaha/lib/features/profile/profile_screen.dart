import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/app.dart';
import '../../app/router.dart';
import '../../app/theme/app_theme.dart';
import '../../core/providers/providers.dart';
import '../../core/utils/formatters.dart';
import '../../data/models/farm_profile.dart';
import '../../shared/widgets/common_widgets.dart';

class ProfileScreen extends ConsumerWidget {
  const ProfileScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final profile = ref.watch(profileProvider);
    final hasDemo = ref.watch(hasDemoDataProvider).value ?? false;
    final pendingSync = ref.watch(pendingSyncCountProvider).value ?? 0;
    return Scaffold(
      appBar: AppBar(title: const Text('Profil')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          AsyncValueView<FarmProfile?>(
            value: profile,
            onRetry: () => ref.invalidate(profileProvider),
            data: (profile) => profile == null
                ? SectionCard(
                    child: EmptyState(
                      icon: Icons.person_outline,
                      title: 'Aucun profil',
                      message:
                          'L’application fonctionne sans compte. Un '
                          'profil local permet de préremplir votre région et '
                          'de définir une unité de surface locale.',
                      actionLabel: 'Créer mon profil',
                      onAction: () => context.push(Routes.editProfile),
                    ),
                  )
                : _ProfileCard(profile: profile),
          ),
          const SizedBox(height: 16),
          SectionCard(
            title: 'Mes données',
            icon: Icons.storage,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text(
                  'Toutes vos données sont enregistrées sur ce '
                  'téléphone et restent disponibles sans Internet.',
                ),
                const SizedBox(height: 8),
                InfoRow(
                  label: 'Synchronisation',
                  value: pendingSync == 0
                      ? 'Aucune modification en attente'
                      : '$pendingSync modification(s) locale(s) en attente — '
                            'aucun serveur configuré',
                ),
                const Divider(height: 24),
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  leading: const Icon(Icons.crop_square),
                  title: const Text('Mes parcelles'),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => context.push(Routes.plots),
                ),
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  leading: const Icon(Icons.account_balance_wallet),
                  title: const Text('Finances'),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => context.push(Routes.finances),
                ),
                const Divider(height: 24),
                Text(
                  'Données de démonstration',
                  style: Theme.of(context).textTheme.titleSmall,
                ),
                const SizedBox(height: 4),
                const Text(
                  'Un petit exemple marqué « démo », séparé de vos données '
                  'réelles et jamais synchronisé.',
                ),
                const SizedBox(height: 8),
                if (hasDemo)
                  OutlinedButton.icon(
                    onPressed: () => _clearDemo(context, ref),
                    icon: const Icon(Icons.cleaning_services),
                    label: const Text('Supprimer les données de démo'),
                  )
                else
                  OutlinedButton.icon(
                    onPressed: () => _loadDemo(context, ref),
                    icon: const Icon(Icons.science_outlined),
                    label: const Text('Charger un exemple de démo'),
                  ),
                const Divider(height: 24),
                OutlinedButton.icon(
                  style: OutlinedButton.styleFrom(
                    foregroundColor: AppColors.danger,
                  ),
                  onPressed: () => _deleteAll(context, ref),
                  icon: const Icon(Icons.delete_forever),
                  label: const Text('Effacer toutes mes données'),
                ),
              ],
            ),
          ),
          const SizedBox(height: 16),
          const SectionCard(
            title: 'À propos',
            icon: Icons.info_outline,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('${AgriSahaApp.name} — ${AgriSahaApp.slogan}'),
                SizedBox(height: 8),
                Text(
                  'Les dates de récolte et calendriers sont des '
                  'estimations. Faites valider les choix de variétés, '
                  'fertilisants et traitements par un technicien agricole.',
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Future<void> _loadDemo(BuildContext context, WidgetRef ref) async {
    await runGuarded(context, () async {
      await ref.read(dataMaintenanceProvider).loadDemoData();
      ref.read(dataRevisionProvider.notifier).bump();
    }, successMessage: 'Exemple de démonstration chargé.');
  }

  Future<void> _clearDemo(BuildContext context, WidgetRef ref) async {
    final ok = await confirmDialog(
      context,
      title: 'Supprimer la démo ?',
      message:
          'Seules les données marquées « démo » seront supprimées. '
          'Vos données réelles sont conservées.',
      confirmLabel: 'Supprimer',
    );
    if (!ok || !context.mounted) return;
    await runGuarded(context, () async {
      await ref.read(dataMaintenanceProvider).clearDemoData();
      ref.read(dataRevisionProvider.notifier).bump();
    }, successMessage: 'Données de démonstration supprimées.');
  }

  Future<void> _deleteAll(BuildContext context, WidgetRef ref) async {
    final ok = await confirmDialog(
      context,
      title: 'Effacer toutes les données ?',
      message:
          'Profil, parcelles, cultures, calendriers, journaux, '
          'récoltes et finances seront définitivement effacés de ce '
          'téléphone. Cette action est irréversible.',
      confirmLabel: 'Tout effacer',
      destructive: true,
    );
    if (!ok || !context.mounted) return;
    await runGuarded(context, () async {
      await ref.read(dataMaintenanceProvider).deleteAllData();
      ref.read(dataRevisionProvider.notifier).bump();
    }, successMessage: 'Toutes les données ont été effacées.');
  }
}

class _ProfileCard extends ConsumerWidget {
  const _ProfileCard({required this.profile});

  final FarmProfile profile;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return SectionCard(
      title: profile.displayName,
      icon: Icons.person,
      trailing: IconButton(
        tooltip: 'Modifier',
        icon: const Icon(Icons.edit),
        onPressed: () => context.push(Routes.editProfile),
      ),
      child: Column(
        children: [
          if (profile.farmName != null)
            InfoRow(label: 'Exploitation', value: profile.farmName!),
          InfoRow(
            label: 'Localité',
            value: [
              profile.village,
              profile.commune,
              profile.region,
            ].whereType<String>().join(', '),
          ),
          InfoRow(label: 'Langue', value: profile.language.label),
          if (profile.cropTypes.isNotEmpty)
            InfoRow(
              label: 'Cultures',
              value: profile.cropTypes.map((c) => c.label).join(', '),
            ),
          if (profile.totalArea != null)
            InfoRow(
              label: 'Superficie totale',
              value:
                  '${Formatters.number(profile.totalArea!)} '
                  '${profile.totalAreaUnit.symbol}',
            ),
          if (profile.productionMode != null)
            InfoRow(
              label: 'Mode de production',
              value: profile.productionMode!.label,
            ),
          if (profile.localAreaUnitLabel != null &&
              profile.localAreaUnitHectares != null)
            InfoRow(
              label: 'Unité locale',
              value:
                  '1 ${profile.localAreaUnitLabel} = '
                  '${Formatters.number(profile.localAreaUnitHectares!, maxDecimals: 4)} ha',
            ),
          const SizedBox(height: 8),
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton.icon(
              onPressed: () async {
                final ok = await confirmDialog(
                  context,
                  title: 'Supprimer le profil ?',
                  message:
                      'Seules les informations du profil sont '
                      'supprimées. Vos cultures et récoltes sont conservées.',
                  confirmLabel: 'Supprimer',
                  destructive: true,
                );
                if (!ok || !context.mounted) return;
                await runGuarded(context, () async {
                  await ref.read(profileRepositoryProvider).deleteProfile();
                  ref.read(dataRevisionProvider.notifier).bump();
                }, successMessage: 'Profil supprimé.');
              },
              icon: const Icon(Icons.person_remove_outlined),
              label: const Text('Supprimer le profil'),
            ),
          ),
        ],
      ),
    );
  }
}
