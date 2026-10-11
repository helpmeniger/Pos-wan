import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme/app_theme.dart';
import '../../core/providers/providers.dart';
import '../../core/utils/date_utils.dart';
import '../../core/utils/formatters.dart';
import '../../data/models/crop.dart';
import '../../data/models/crop_task.dart';
import '../../data/models/enums.dart';
import '../../shared/widgets/common_widgets.dart';

/// Calendrier agricole d'une culture, regroupé par échéance.
class CropCalendarTab extends ConsumerWidget {
  const CropCalendarTab({super.key, required this.crop});

  final Crop crop;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final tasks = ref.watch(cropTasksProvider(crop.id));
    final today = ref.watch(todayProvider);
    return AsyncValueView<List<CropTask>>(
      value: tasks,
      onRetry: () => ref.invalidate(cropTasksProvider(crop.id)),
      data: (tasks) {
        if (tasks.isEmpty) {
          return const EmptyState(
            icon: Icons.event_note,
            title: 'Aucune tâche',
            message:
                'Aucun modèle de calendrier ne correspond à cette culture.',
          );
        }
        final overdue = tasks.where((t) => t.isOverdue(today)).toList();
        final current = tasks.where((t) => t.isDueOn(today)).toList();
        final upcoming = tasks
            .where((t) => t.status.isPending && t.dueDate.isAfter(today))
            .toList();
        final done = tasks.where((t) => t.status == TaskStatus.done).toList();
        final notTracked = tasks
            .where((t) => t.status == TaskStatus.notTracked)
            .toList();
        return ListView(
          padding: const EdgeInsets.all(16),
          children: [
            const InfoBanner(
              message:
                  'Calendrier indicatif établi à partir de la date de '
                  'semis et de la récolte estimée. Adaptez-le avec votre '
                  'conseiller agricole (aucun produit ni dose n’est '
                  'recommandé ici).',
            ),
            _group(context, 'En retard', overdue, today, AppColors.danger),
            _group(
              context,
              'À faire maintenant',
              current,
              today,
              AppColors.harvestYellow,
            ),
            _group(context, 'À venir', upcoming, today, AppColors.green),
            _group(context, 'Effectuées', done, today, AppColors.textMuted),
            _group(
              context,
              'Antérieures à l’enregistrement',
              notTracked,
              today,
              AppColors.textMuted,
            ),
          ],
        );
      },
    );
  }

  Widget _group(
    BuildContext context,
    String title,
    List<CropTask> tasks,
    DateTime today,
    Color color,
  ) {
    if (tasks.isEmpty) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.only(top: 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            '$title (${tasks.length})',
            style: Theme.of(context).textTheme.titleMedium
                ?.copyWith(color: color),
          ),
          const SizedBox(height: 8),
          for (final task in tasks)
            Padding(
              padding: const EdgeInsets.only(bottom: 10),
              child: TaskCard(task: task, today: today, accent: color),
            ),
        ],
      ),
    );
  }
}

class TaskCard extends ConsumerWidget {
  const TaskCard({
    super.key,
    required this.task,
    required this.today,
    required this.accent,
  });

  final CropTask task;
  final DateTime today;
  final Color accent;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final sameDay = DateOnly.daysBetween(task.dueDate, task.windowEndDate) == 0;
    final period = sameDay
        ? Formatters.date(task.dueDate)
        : 'Du ${Formatters.shortDate(task.dueDate)} au '
              '${Formatters.date(task.windowEndDate)}';
    return Card(
      child: Container(
        decoration: BoxDecoration(
          border: Border(left: BorderSide(color: accent, width: 5)),
        ),
        padding: const EdgeInsets.fromLTRB(14, 12, 8, 8),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(task.title, style: theme.textTheme.titleSmall),
                ),
                Chip(
                  label: Text(task.status.label),
                  visualDensity: VisualDensity.compact,
                ),
              ],
            ),
            Text(
              period,
              style: theme.textTheme.bodySmall?.copyWith(
                fontWeight: FontWeight.w600,
              ),
            ),
            const SizedBox(height: 4),
            Text(task.description, style: theme.textTheme.bodySmall),
            if (task.note != null) ...[
              const SizedBox(height: 6),
              Text(
                'Note : ${task.note}',
                style: theme.textTheme.bodySmall?.copyWith(
                  fontStyle: FontStyle.italic,
                ),
              ),
            ],
            if (task.completedAt != null && task.status == TaskStatus.done)
              Text(
                'Effectuée le ${Formatters.date(task.completedAt!)}',
                style: theme.textTheme.bodySmall,
              ),
            Wrap(
              spacing: 4,
              children: [
                if (task.status != TaskStatus.done)
                  TextButton.icon(
                    onPressed: () => _markDone(context, ref),
                    icon: const Icon(Icons.check_circle_outline),
                    label: const Text('C’est fait'),
                  ),
                if (task.status.isPending)
                  TextButton.icon(
                    onPressed: () => _postpone(context, ref),
                    icon: const Icon(Icons.schedule),
                    label: const Text('Reporter'),
                  ),
                if (task.status == TaskStatus.done)
                  TextButton.icon(
                    onPressed: () => _reopen(context, ref),
                    icon: const Icon(Icons.undo),
                    label: const Text('Annuler'),
                  ),
                TextButton.icon(
                  onPressed: () => _editNote(context, ref),
                  icon: const Icon(Icons.edit_note),
                  label: const Text('Note'),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _markDone(BuildContext context, WidgetRef ref) async {
    final note = await _askNote(
      context,
      'Confirmer la réalisation',
      hint: 'Note facultative (ex. réalisé avec 3 personnes)',
    );
    if (note == null || !context.mounted) return;
    await runGuarded(context, () async {
      await ref.read(taskRepositoryProvider).markDone(task.id, note: note);
      ref.read(dataRevisionProvider.notifier).bump();
    }, successMessage: 'Tâche confirmée.');
  }

  Future<void> _postpone(BuildContext context, WidgetRef ref) async {
    final picked = await showDatePicker(
      context: context,
      initialDate: DateOnly.addDays(today, 1),
      firstDate: today,
      lastDate: DateOnly.addDays(today, 180),
      locale: const Locale('fr', 'FR'),
      helpText: 'Reporter au',
    );
    if (picked == null || !context.mounted) return;
    await runGuarded(context, () async {
      await ref.read(taskRepositoryProvider).postpone(task.id, picked);
      ref.read(dataRevisionProvider.notifier).bump();
    }, successMessage: 'Tâche reportée au ${Formatters.date(picked)}.');
  }

  Future<void> _reopen(BuildContext context, WidgetRef ref) async {
    await runGuarded(context, () async {
      await ref.read(taskRepositoryProvider).reopen(task.id);
      ref.read(dataRevisionProvider.notifier).bump();
    }, successMessage: 'Tâche remise à faire.');
  }

  Future<void> _editNote(BuildContext context, WidgetRef ref) async {
    final note = await _askNote(
      context,
      'Note de la tâche',
      initial: task.note ?? '',
    );
    if (note == null || !context.mounted) return;
    await runGuarded(context, () async {
      await ref.read(taskRepositoryProvider).updateNote(task.id, note);
      ref.read(dataRevisionProvider.notifier).bump();
    }, successMessage: 'Note enregistrée.');
  }

  /// Retourne la note saisie ('' si vide) ou `null` si l'utilisateur annule.
  Future<String?> _askNote(
    BuildContext context,
    String title, {
    String initial = '',
    String? hint,
  }) {
    return showDialog<String>(
      context: context,
      builder: (context) =>
          _NoteDialog(title: title, initial: initial, hint: hint),
    );
  }
}

class _NoteDialog extends StatefulWidget {
  const _NoteDialog({required this.title, required this.initial, this.hint});

  final String title;
  final String initial;
  final String? hint;

  @override
  State<_NoteDialog> createState() => _NoteDialogState();
}

class _NoteDialogState extends State<_NoteDialog> {
  late final _controller = TextEditingController(text: widget.initial);

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: Text(widget.title),
      content: TextField(
        controller: _controller,
        maxLines: 3,
        maxLength: 500,
        decoration: InputDecoration(hintText: widget.hint ?? 'Note'),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('Annuler'),
        ),
        FilledButton(
          onPressed: () => Navigator.of(context).pop(_controller.text),
          child: const Text('Valider'),
        ),
      ],
    );
  }
}
