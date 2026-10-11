import 'package:flutter/material.dart';

import '../../core/utils/date_utils.dart';
import '../../core/utils/formatters.dart';

/// Champ de date avec calendrier (sélecteur en français).
class DateField extends FormField<DateTime> {
  DateField({
    super.key,
    required String label,
    required DateTime? value,
    required ValueChanged<DateTime> onChanged,
    required DateTime firstDate,
    required DateTime lastDate,
    String? helperText,
    super.validator,
  }) : super(
         initialValue: value,
         builder: (state) {
           final context = state.context;
           return InkWell(
             borderRadius: BorderRadius.circular(14),
             onTap: () async {
               final initial = state.value ?? DateOnly.today();
               final picked = await showDatePicker(
                 context: context,
                 initialDate: initial.isBefore(firstDate)
                     ? firstDate
                     : initial.isAfter(lastDate)
                     ? lastDate
                     : initial,
                 firstDate: firstDate,
                 lastDate: lastDate,
                 locale: const Locale('fr', 'FR'),
                 helpText: label,
               );
               if (picked != null) {
                 final date = DateOnly.of(picked);
                 state.didChange(date);
                 onChanged(date);
               }
             },
             child: InputDecorator(
               decoration: InputDecoration(
                 labelText: label,
                 helperText: helperText,
                 helperMaxLines: 3,
                 errorText: state.errorText,
                 suffixIcon: const Icon(Icons.calendar_month),
               ),
               child: Text(
                 state.value == null
                     ? 'Choisir une date'
                     : Formatters.longDate(state.value!),
               ),
             ),
           );
         },
       );
}

/// Liste déroulante générique.
class SelectField<T> extends StatelessWidget {
  const SelectField({
    super.key,
    required this.label,
    required this.value,
    required this.items,
    required this.itemLabel,
    required this.onChanged,
    this.validator,
    this.helperText,
  });

  final String label;
  final T? value;
  final List<T> items;
  final String Function(T item) itemLabel;
  final ValueChanged<T?> onChanged;
  final FormFieldValidator<T>? validator;
  final String? helperText;

  @override
  Widget build(BuildContext context) {
    return DropdownButtonFormField<T>(
      initialValue: items.contains(value) ? value : null,
      isExpanded: true,
      decoration: InputDecoration(
        labelText: label,
        helperText: helperText,
        helperMaxLines: 3,
      ),
      items: [
        for (final item in items)
          DropdownMenuItem<T>(
            value: item,
            child: Text(itemLabel(item), overflow: TextOverflow.ellipsis),
          ),
      ],
      onChanged: onChanged,
      validator: validator,
    );
  }
}

/// Espacement standard entre champs de formulaire.
const formGap = SizedBox(height: 16);
