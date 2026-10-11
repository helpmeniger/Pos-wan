import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/router.dart';
import '../../app/theme/app_theme.dart';
import '../../core/providers/providers.dart';
import '../../core/utils/date_utils.dart';
import '../../core/utils/formatters.dart';
import '../../data/models/crop.dart';
import '../../data/models/enums.dart';
import '../../data/models/finance_entry.dart';
import '../../domain/finance_calculator.dart';
import '../../shared/widgets/bar_chart.dart';
import '../../shared/widgets/common_widgets.dart';
import '../../shared/widgets/form_fields.dart';

enum _Period {
  all('Toute la période'),
  days30('30 derniers jours'),
  year('12 derniers mois');

  const _Period(this.label);
  final String label;
}

/// Journal financier : dépenses, recettes et marge, filtrables par période
/// et par culture.
class FinancesScreen extends ConsumerStatefulWidget {
  const FinancesScreen({super.key});

  @override
  ConsumerState<FinancesScreen> createState() => _FinancesScreenState();
}

class _FinancesScreenState extends ConsumerState<FinancesScreen> {
  _Period _period = _Period.all;
  String? _cropId;

  @override
  Widget build(BuildContext context) {
    final entries = ref.watch(financeEntriesProvider);
    final crops = ref.watch(cropsProvider).value ?? const <Crop>[];
    final today = ref.watch(todayProvider);
    final filter = FinanceFilter(
      from: switch (_period) {
        _Period.all => null,
        _Period.days30 => DateOnly.addDays(today, -30),
        _Period.year => DateOnly.addDays(today, -365),
      },
      cropId: _cropId,
    );
    return Scaffold(
      appBar: AppBar(title: const Text('Finances')),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => context.push(Routes.newFinanceEntry),
        icon: const Icon(Icons.add),
        label: const Text('Ajouter'),
      ),
      body: AsyncValueView<List<FinanceEntry>>(
        value: entries,
        onRetry: () => ref.invalidate(financeEntriesProvider),
        data: (all) {
          if (all.isEmpty) {
            return EmptyState(
              icon: Icons.account_balance_wallet_outlined,
              title: 'Aucune écriture',
              message:
                  'Enregistrez vos dépenses (semences, engrais, '
                  'main-d’œuvre…) et vos recettes pour connaître votre marge.',
              actionLabel: 'Ajouter une dépense ou recette',
              onAction: () => context.push(Routes.newFinanceEntry),
            );
          }
          final filtered = all.where(filter.matches).toList();
          final summary = FinanceCalculator.summarize(all, filter: filter);
          final cropNames = {for (final c in crops) c.id: c.displayName};
          return ListView(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 96),
            children: [
              Row(
                children: [
                  Expanded(
                    child: SelectField<_Period>(
                      label: 'Période',
                      value: _period,
                      items: _Period.values,
                      itemLabel: (p) => p.label,
                      onChanged: (p) => setState(() => _period = p ?? _period),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: SelectField<String>(
                      label: 'Culture',
                      value: _cropId ?? '',
                      items: ['', ...crops.map((c) => c.id)],
                      itemLabel: (id) =>
                          id.isEmpty ? 'Toutes' : (cropNames[id] ?? id),
                      onChanged: (id) => setState(
                        () => _cropId = (id == null || id.isEmpty) ? null : id,
                      ),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 16),
              SectionCard(
                title: 'Résumé',
                icon: Icons.insights,
                child: Column(
                  children: [
                    HorizontalBarChart(
                      data: [
                        BarDatum(
                          label: 'Dépenses',
                          value: summary.totalExpenses,
                          valueLabel: Formatters.money(summary.totalExpenses),
                          color: AppColors.danger,
                        ),
                        BarDatum(
                          label: 'Recettes',
                          value: summary.totalIncomes,
                          valueLabel: Formatters.money(summary.totalIncomes),
                        ),
                      ],
                    ),
                    const Divider(height: 24),
                    InfoRow(
                      label: 'Marge estimée',
                      value: Formatters.money(summary.margin),
                    ),
                  ],
                ),
              ),
              if (summary.byCategory.isNotEmpty) ...[
                const SizedBox(height: 16),
                SectionCard(
                  title: 'Par catégorie',
                  icon: Icons.category,
                  child: HorizontalBarChart(
                    data: [
                      for (final e in summary.byCategory.entries)
                        BarDatum(
                          label: e.key.label,
                          value: e.value,
                          valueLabel: Formatters.money(e.value),
                          color: e.key.kind == FinanceKind.expense
                              ? AppColors.danger
                              : AppColors.green,
                        ),
                    ],
                  ),
                ),
              ],
              const SizedBox(height: 16),
              Text(
                'Écritures (${filtered.length})',
                style: Theme.of(context).textTheme.titleMedium,
              ),
              const SizedBox(height: 8),
              for (final entry in filtered)
                _EntryTile(
                  entry: entry,
                  cropName: entry.cropId == null
                      ? null
                      : cropNames[entry.cropId],
                ),
            ],
          );
        },
      ),
    );
  }
}

class _EntryTile extends ConsumerWidget {
  const _EntryTile({required this.entry, this.cropName});

  final FinanceEntry entry;
  final String? cropName;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final isExpense = entry.kind == FinanceKind.expense;
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: ListTile(
        leading: Icon(
          isExpense ? Icons.arrow_upward : Icons.arrow_downward,
          color: isExpense ? AppColors.danger : AppColors.green,
        ),
        title: Text(entry.category.label),
        subtitle: Text(
          [
            Formatters.date(entry.entryDate),
            ?cropName,
            ?entry.description,
          ].join(' · '),
        ),
        trailing: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              '${isExpense ? '−' : '+'}${Formatters.money(entry.amount)}',
              style: TextStyle(
                fontWeight: FontWeight.w700,
                color: isExpense ? AppColors.danger : AppColors.darkGreen,
              ),
            ),
            IconButton(
              tooltip: 'Supprimer',
              icon: const Icon(Icons.delete_outline),
              onPressed: () => _delete(context, ref),
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _delete(BuildContext context, WidgetRef ref) async {
    final ok = await confirmDialog(
      context,
      title: 'Supprimer cette écriture ?',
      message: '${entry.category.label} de ${Formatters.money(entry.amount)}.',
      confirmLabel: 'Supprimer',
      destructive: true,
    );
    if (!ok || !context.mounted) return;
    await runGuarded(context, () async {
      await ref.read(financeRepositoryProvider).delete(entry.id);
      ref.read(dataRevisionProvider.notifier).bump();
    }, successMessage: 'Écriture supprimée.');
  }
}
