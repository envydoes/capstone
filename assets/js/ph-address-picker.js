/**
 * PHAddressPicker — cascading Province → City/Municipality → Barangay dropdowns.
 *
 * Solves free-text inconsistency ("Sumacab Este" vs "Sumaca Este") by making
 * province/city/barangay controlled selections instead of typed text, wherever
 * we have verified reference data.
 *
 * Data source: /assets/data/ph-address.json (see its "_source_note" for provenance).
 * That file currently has full City/Municipality coverage for Nueva Ecija and
 * Metro Manila, and full Barangay coverage for Cabanatuan City only. For any
 * province/city NOT in the dataset, the corresponding field gracefully falls
 * back to the existing free-text <input> instead of forcing an empty dropdown —
 * so the form still works for residents of other provinces/cities, it just
 * isn't strict for those (yet). Extend the JSON file to cover more areas.
 */
(function (window, document) {
  'use strict';

  function PHAddressPicker(opts) {
    this.dataUrl        = opts.dataUrl;
    this.provinceSelect = document.getElementById(opts.provinceId);
    this.cityWrap       = document.getElementById(opts.cityWrapId); // container div we can swap select<->input inside
    this.barangayWrap   = document.getElementById(opts.barangayWrapId);
    this.cityFieldName  = opts.cityFieldName;
    this.barangayFieldName = opts.barangayFieldName;
    this.onChange       = opts.onChange || function () {};

    // Old/current values to restore on load (e.g. after a validation error re-render, or when editing an existing profile).
    this.oldProvince  = opts.oldProvince  || '';
    this.oldCity      = opts.oldCity      || '';
    this.oldBarangay  = opts.oldBarangay  || '';

    this.data = null;
    this._buildProvinceOptions = this._buildProvinceOptions.bind(this);

    this._init();
  }

  PHAddressPicker.prototype._init = function () {
    var self = this;
    fetch(this.dataUrl)
      .then(function (res) {
        if (!res.ok) throw new Error('Failed to load PH address data (' + res.status + ')');
        return res.json();
      })
      .then(function (data) {
        self.data = data;
        self._buildProvinceOptions();
        self.provinceSelect.disabled = false;

        // Restore province, then cascade down.
        if (self.oldProvince && self._provinceExists(self.oldProvince)) {
          self.provinceSelect.value = self.oldProvince;
        }
        self._renderCityField(self.provinceSelect.value, self.oldCity, self.oldBarangay);

        self.provinceSelect.addEventListener('change', function () {
          self._renderCityField(self.provinceSelect.value, '', '');
          self.onChange();
        });
      })
      .catch(function (err) {
        console.error('PHAddressPicker:', err);
        // Fail open: leave province as a plain text fallback so the form is never blocked by a data-load failure.
        self._fallbackToText(self.provinceSelect, 'province', self.oldProvince, 'Province');
        self._renderTextFallback(self.cityWrap, self.cityFieldName, self.oldCity, 'City / Municipality', 'city');
        self._renderTextFallback(self.barangayWrap, self.barangayFieldName, self.oldBarangay, 'Barangay', 'barangay');
      });
  };

  PHAddressPicker.prototype._provinceExists = function (name) {
    return this.data.provinces.indexOf(name) !== -1;
  };

  PHAddressPicker.prototype._buildProvinceOptions = function () {
    var select = this.provinceSelect;
    select.innerHTML = '<option value="">Select Province</option>';
    this.data.provinces.forEach(function (p) {
      var opt = document.createElement('option');
      opt.value = p;
      opt.textContent = p;
      select.appendChild(opt);
    });
  };

  // Render either a City <select> (if we have data for this province) or a text <input> fallback.
  PHAddressPicker.prototype._renderCityField = function (province, presetCity, presetBarangay) {
    var self = this;
    var cities = (this.data.cities && this.data.cities[province]) || null;

    this.cityWrap.innerHTML = '';

    if (cities && cities.length) {
      var select = document.createElement('select');
      select.name = this.cityFieldName;
      select.id = this.cityFieldName;
      select.required = true;
      select.className = 'field-input tracked-field';
      select.innerHTML = '<option value="">Select City / Municipality</option>';
      cities.forEach(function (c) {
        var opt = document.createElement('option');
        opt.value = c;
        opt.textContent = c;
        select.appendChild(opt);
      });
      if (presetCity && cities.indexOf(presetCity) !== -1) select.value = presetCity;
      this.cityWrap.appendChild(select);

      select.addEventListener('change', function () {
        self._renderBarangayField(select.value, '');
        self.onChange();
      });

      this._renderBarangayField(select.value, presetBarangay);
    } else {
      // No verified city list for this province yet — free text, same as before.
      this._renderTextFallback(this.cityWrap, this.cityFieldName, presetCity, 'City / Municipality', 'city');
      this._renderBarangayField('', presetBarangay); // no city selected -> barangay also falls back to text
    }
  };

  // Render either a Barangay <select> (if we have data for this city) or a text <input> fallback.
  PHAddressPicker.prototype._renderBarangayField = function (city, presetBarangay) {
    var barangays = (this.data.barangays && this.data.barangays[city]) || null;

    this.barangayWrap.innerHTML = '';

    if (barangays && barangays.length) {
      var select = document.createElement('select');
      select.name = this.barangayFieldName;
      select.id = this.barangayFieldName;
      select.required = true;
      select.className = 'field-input tracked-field';
      select.innerHTML = '<option value="">Select Barangay</option>';
      barangays.forEach(function (b) {
        var opt = document.createElement('option');
        opt.value = b;
        opt.textContent = b;
        select.appendChild(opt);
      });
      if (presetBarangay && barangays.indexOf(presetBarangay) !== -1) select.value = presetBarangay;
      this.barangayWrap.appendChild(select);
    } else {
      this._renderTextFallback(this.barangayWrap, this.barangayFieldName, presetBarangay, 'Barangay', 'barangay');
    }
  };

  PHAddressPicker.prototype._renderTextFallback = function (wrap, name, value, placeholder, idBase) {
    var input = document.createElement('input');
    input.type = 'text';
    input.name = name;
    input.id = idBase;
    input.required = true;
    input.maxLength = 100;
    input.className = 'field-input tracked-field';
    input.placeholder = placeholder;
    input.value = value || '';
    wrap.appendChild(input);
  };

  PHAddressPicker.prototype._fallbackToText = function (existingSelect, name, value, placeholder) {
    var input = document.createElement('input');
    input.type = 'text';
    input.name = name;
    input.id = name;
    input.required = true;
    input.maxLength = 100;
    input.className = existingSelect.className.replace('tracked-field', '') + ' tracked-field';
    input.placeholder = placeholder;
    input.value = value || '';
    existingSelect.parentNode.replaceChild(input, existingSelect);
  };

  window.PHAddressPicker = PHAddressPicker;
})(window, document);