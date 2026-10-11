import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/providers/providers.dart';
import '../../core/utils/date_utils.dart';
import '../../core/utils/formatters.dart';
import '../../core/validation/validators.dart';
import '../../data/models/crop.dart';
import '../../data/models/enums.dart';
import '../../shared/widgets/common_widgets.dart';
import '../../shared/widgets/form_fields.dart';

class FinanceEntryFormScreen extends ConsumerStatefulWidget {
  const FinanceEntryFormScreen({super.key, this.initialCropId});

  final String? initialCropId;

  @override
  ConsumerState<FinanceEntryFormScreen> createState() =>
      _FinanceEntryFormScreenState();
}

class _FinanceEntryFormScreenState
    extends ConsumerState<FinanceEntryFormScreen> {
  final _formKey = GlobalKey<FormState>();
  final _amount = TextEditingController();
  final _description = TextEditingController();
  FinanceKind _kind = FinanceKind.expense;
  FinanceCategory _category = FinanceCategory.semences;
  late DateTime _date = ref.read(todayProvider);
  late String? _cropId = widget.initialCropId;
  bool _saving = false;

  @override
  void dispose() {
    _amount.dispose();
    _description.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final crops = ref.watch(cropsProvider).value ?? const <Crop>[];
    final today = ref.watch(todayProvider);
    final categories = FinanceCategory.forKind(_kind);
    return Scaffold(
      appBar: AppBar(title: const Text('Dépense ou recette')),
      body: Form(
        key: _formKey,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            SegmentedButton<FinanceKind>(
              segments: const [
                ButtonSegment(
                  value: FinanceKind.expense,
                  label: Text('Dépense'),
                  icon: Icon(Icons.arrow_upward),
                ),
                ButtonSegment(
                  value: FinanceKind.income,
                  label: Text('Recette'),
                  icon: Icon(Icons.arrow_downward),
                ),
              ],
              selected: {_kind},
              onSelectionChanged: (s) => setState(() {
                _kind = s.first;
                _category = FinanceCategory.forKind(_kind).first;
              }),
            ),
            formGap,
            SelectField<FinanceCategory>(
              key: ValueKey(_kind),
              label: 'Catégorie',
              value: _category,
              items: categories,
              itemLabel: (c) => c.label,
              onChanged: (c) => setState(() => _category = c ?? _category),
            ),
            formGap,
            TextFormField(
              controller: _amount,
              decoration: const InputDecoration(
                labelText: 'Montant (FCFA) *',
                suffixText: 'FCFA',
              ),
              keyboardType: const TextInputType.numberWithOptions(
                decimal: true,
              ),
              validator: (v) =>
                  Validators.positiveNumber(v, field: 'Le montant'),
            ),
            formGap,
            DateField(
              label: 'Date',
              value: _date,
              firstDate: DateOnly.addDays(today, -1095),
              lastDate: today,
              onChanged: (d) => setState(() => _date = d),
            ),
            formGap,
            SelectField<String>(
              label: 'Culture concernée (facultatif)',
              value: _cropId ?? '',
              items: ['', ...crops.map((c) => c.id)],
              itemLabel: (id) => id.isEmpty
                  ? 'Aucune / générale'
                  : crops.firstWhere((c) => c.id == id).displayName,
              onChanged: (id) => setState(
                () => _cropId = (id == null || id.isEmpty) ? null : id,
              ),
            ),
            formGap,
            TextFormField(
              controller: _description,
              decoration: const InputDecoration(labelText: 'Description'),
              validator: Validators.optionalText,
            ),
            const SizedBox(height: 24),
            FilledButton.icon(
              onPressed: _saving ? null : () => _save(crops),
              icon: const Icon(Icons.save),
              label: Text(_saving ? 'Enregistrement…' : 'Enregistrer'),
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _save(List<Crop> crops) async {
    if (!_formKey.currentState!.validate()) return;
    setState(() => _saving = true);
    String? plotId;
    for (final crop in crops) {
      if (crop.id == _cropId) plotId = crop.plotId;
    }
    final ok = await runGuarded(context, () async {
      await ref
          .read(financeRepositoryProvider)
          .add(
            category: _category,
            amount: Formatters.parseDecimal(_amount.text)!,
            entryDate: _date,
            cropId: _cropId,
            plotId: plotId,
            description: _description.text,
          );
      ref.read(dataRevisionProvider.notifier).bump();
    }, successMessage: '${_kind.label} enregistrée.');
    if (!mounted) return;
    setState(() => _saving = false);
    if (ok) context.pop();
  }
}
