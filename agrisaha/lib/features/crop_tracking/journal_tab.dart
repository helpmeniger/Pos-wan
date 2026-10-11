import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/router.dart';
import '../../app/theme/app_theme.dart';
import '../../core/providers/providers.dart';
import '../../core/utils/formatters.dart';
import '../../data/models/crop.dart';
import '../../data/models/enums.dart';
import '../../data/models/journal_entry.dart';
import '../../shared/widgets/common_widgets.dart';

/// Chronologie du journal de suivi d'une culture.
class JournalTab extends ConsumerWidget {
  const JournalTab({super.key, required this.crop});

  final Crop crop;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final entries = ref.watch(cropJournalProvider(crop.id));
    return Scaffold(
      backgroundColor: Colors.transparent,
      floatingActionButton: FloatingActionButton.extended(
        heroTag: 'journal-${crop.id}',
        onPressed: () => context.push(Routes.newJournalEntry(crop.id)),
        icon: const Icon(Icons.add),
        label: const Text('Ajouter'),
      ),
      body: AsyncValueView<List<JournalEntry>>(
        value: entries,
        onRetry: () => ref.invalidate(cropJournalProvider(crop.id)),
        data: (entries) {
          if (entries.isEmpty) {
            return EmptyState(
              icon: Icons.menu_book,
              title: 'Journal vide',
              message:
                  'Notez vos observations, travaux, apports d’eau ou '
                  'd’engrais, traitements et problèmes constatés.',
              actionLabel: 'Ajouter une observation',
              onAction: () => context.push(Routes.newJournalEntry(crop.id)),
            );
          }
          return ListView.builder(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 96),
            itemCount: entries.length,
            itemBuilder: (context, index) => _TimelineItem(
              entry: entries[index],
              isLast: index == entries.length - 1,
            ),
          );
        },
      ),
    );
  }
}

class _TimelineItem extends ConsumerWidget {
  const _TimelineItem({required this.entry, required this.isLast});

  final JournalEntry entry;
  final bool isLast;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final isProblem = entry.type == JournalEntryType.problem;
    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SizedBox(
            width: 28,
            child: Column(
              children: [
                const SizedBox(height: 6),
                Icon(
                  Icons.circle,
                  size: 14,
                  color: isProblem ? AppColors.danger : AppColors.green,
                ),
                if (!isLast)
                  const Expanded(
                    child: VerticalDivider(
                      width: 2,
                      thickness: 2,
                      color: AppColors.sand,
                    ),
                  ),
              ],
            ),
          ),
          Expanded(
            child: Padding(
              padding: const EdgeInsets.only(bottom: 16),
              child: Card(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(14, 10, 4, 10),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          Expanded(
                            child: Text(
                              '${Formatters.date(entry.entryDate)} · ${entry.type.label}',
                              style: theme.textTheme.bodySmall?.copyWith(
                                color: AppColors.textMuted,
                              ),
                            ),
                          ),
                          IconButton(
                            tooltip: 'Supprimer',
                            icon: const Icon(Icons.delete_outline, size: 20),
                            onPressed: () => _delete(context, ref),
                          ),
                        ],
                      ),
                      Text(entry.title, style: theme.textTheme.titleSmall),
                      if (entry.details != null) ...[
                        const SizedBox(height: 4),
                        Text(entry.details!),
                      ],
                      if (entry.quantity != null)
                        Text(
                          'Quantité : ${Formatters.number(entry.quantity!)} '
                          '${entry.quantityUnit ?? ''}',
                          style: theme.textTheme.bodySmall,
                        ),
                      if (entry.photoPath != null) ...[
                        const SizedBox(height: 8),
                        ClipRRect(
                          borderRadius: BorderRadius.circular(12),
                          child: Image.file(
                            File(entry.photoPath!),
                            height: 160,
                            fit: BoxFit.cover,
                            errorBuilder: (_, _, _) =>
                                const Text('Photo introuvable.'),
                          ),
                        ),
                      ],
                    ],
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Future<void> _delete(BuildContext context, WidgetRef ref) async {
    final ok = await confirmDialog(
      context,
      title: 'Supprimer cette entrée ?',
      message: '« ${entry.title} » sera retirée du journal.',
      confirmLabel: 'Supprimer',
      destructive: true,
    );
    if (!ok || !context.mounted) return;
    await runGuarded(context, () async {
      await ref.read(journalRepositoryProvider).delete(entry.id);
      ref.read(dataRevisionProvider.notifier).bump();
    }, successMessage: 'Entrée supprimée.');
  }
}
