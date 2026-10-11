import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/providers/providers.dart';
import '../../core/utils/date_utils.dart';
import '../../core/utils/formatters.dart';
import '../../core/validation/validators.dart';
import '../../data/models/enums.dart';
import '../../shared/widgets/common_widgets.dart';
import '../../shared/widgets/form_fields.dart';
import 'crop_form_screen.dart' show runPhotoPicker;

class JournalEntryFormScreen extends ConsumerStatefulWidget {
  const JournalEntryFormScreen({super.key, required this.cropId});

  final String cropId;

  @override
  ConsumerState<JournalEntryFormScreen> createState() =>
      _JournalEntryFormScreenState();
}

class _JournalEntryFormScreenState
    extends ConsumerState<JournalEntryFormScreen> {
  final _formKey = GlobalKey<FormState>();
  final _title = TextEditingController();
  final _details = TextEditingController();
  final _quantity = TextEditingController();
  final _unit = TextEditingController();
  JournalEntryType _type = JournalEntryType.observation;
  late DateTime _date = ref.read(todayProvider);
  String? _photoPath;
  bool _saving = false;

  bool get _hasQuantity =>
      _type == JournalEntryType.irrigation ||
      _type == JournalEntryType.fertilization ||
      _type == JournalEntryType.treatment;

  @override
  void dispose() {
    for (final c in [_title, _details, _quantity, _unit]) {
      c.dispose();
    }
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final today = ref.watch(todayProvider);
    return Scaffold(
      appBar: AppBar(title: const Text('Journal de suivi')),
      body: Form(
        key: _formKey,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            SelectField<JournalEntryType>(
              label: 'Type d’entrée',
              value: _type,
              items: JournalEntryType.values,
              itemLabel: (t) => t.label,
              onChanged: (t) => setState(() => _type = t ?? _type),
            ),
            formGap,
            DateField(
              label: 'Date',
              value: _date,
              firstDate: DateOnly.addDays(today, -730),
              lastDate: today,
              onChanged: (d) => setState(() => _date = d),
            ),
            formGap,
            TextFormField(
              controller: _title,
              decoration: const InputDecoration(
                labelText: 'Titre *',
                hintText: 'Ex. Feuilles jaunes en bordure',
              ),
              textCapitalization: TextCapitalization.sentences,
              validator: (v) => Validators.requiredText(v, field: 'Le titre'),
            ),
            formGap,
            TextFormField(
              controller: _details,
              decoration: const InputDecoration(labelText: 'Détails'),
              maxLines: 4,
              validator: (v) =>
                  Validators.optionalText(v, max: Validators.maxNotesLength),
            ),
            if (_hasQuantity) ...[
              formGap,
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(
                    child: TextFormField(
                      controller: _quantity,
                      decoration: const InputDecoration(labelText: 'Quantité'),
                      keyboardType: const TextInputType.numberWithOptions(
                        decimal: true,
                      ),
                      validator: Validators.optionalPositiveNumber,
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: TextFormField(
                      controller: _unit,
                      decoration: const InputDecoration(
                        labelText: 'Unité',
                        hintText: 'litres, kg…',
                      ),
                      validator: Validators.optionalText,
                    ),
                  ),
                ],
              ),
            ],
            if (_type == JournalEntryType.treatment) ...[
              formGap,
              const InfoBanner(
                message:
                    'Ne notez ici que les traitements réellement '
                    'effectués. Faites valider produits et doses par un '
                    'technicien agricole.',
              ),
            ],
            formGap,
            if (_photoPath == null)
              OutlinedButton.icon(
                onPressed: () async {
                  final path = await runPhotoPicker(context);
                  if (path != null) setState(() => _photoPath = path);
                },
                icon: const Icon(Icons.add_a_photo),
                label: const Text('Ajouter une photo (facultatif)'),
              )
            else
              Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  ClipRRect(
                    borderRadius: BorderRadius.circular(14),
                    child: Image.file(
                      File(_photoPath!),
                      height: 160,
                      fit: BoxFit.cover,
                    ),
                  ),
                  TextButton.icon(
                    onPressed: () => setState(() => _photoPath = null),
                    icon: const Icon(Icons.delete_outline),
                    label: const Text('Retirer la photo'),
                  ),
                ],
              ),
            const SizedBox(height: 24),
            FilledButton.icon(
              onPressed: _saving ? null : _save,
              icon: const Icon(Icons.save),
              label: Text(_saving ? 'Enregistrement…' : 'Enregistrer'),
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() => _saving = true);
    final ok = await runGuarded(context, () async {
      await ref
          .read(journalRepositoryProvider)
          .add(
            cropId: widget.cropId,
            entryDate: _date,
            type: _type,
            title: _title.text,
            details: _details.text,
            quantity: _hasQuantity
                ? Formatters.parseDecimal(_quantity.text)
                : null,
            quantityUnit: _unit.text,
            photoPath: _photoPath,
          );
      ref.read(dataRevisionProvider.notifier).bump();
    }, successMessage: 'Entrée ajoutée au journal.');
    if (!mounted) return;
    setState(() => _saving = false);
    if (ok) context.pop();
  }
}
