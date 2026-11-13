/*
 * The event editor (admin/admin_event_edit.php), for a series: the monthly
 * and end choices show their fields, and "Which week" names the start day.
 *
 * @version 1.0
 */
document.addEventListener("DOMContentLoaded", function() {
	// Monthly type radio toggle
	var monthlyByDate = document.getElementById("monthly_by_date");
	var monthlyByWeek = document.getElementById("monthly_by_week");
	var weekOfMonthSelect = document.getElementById("week_of_month_select");

	if (monthlyByDate && monthlyByWeek && weekOfMonthSelect) {
		monthlyByDate.addEventListener("change", function() {
			if (this.checked) weekOfMonthSelect.style.display = "none";
		});
		monthlyByWeek.addEventListener("change", function() {
			if (this.checked) weekOfMonthSelect.style.display = "";
		});
	}

	// End type radio toggle
	var endNever = document.getElementById("end_never");
	var endOnDate = document.getElementById("end_on_date");
	var endDateInput = document.getElementById("recurrence_end_date_input");

	if (endNever && endOnDate && endDateInput) {
		endNever.addEventListener("change", function() {
			if (this.checked) endDateInput.style.display = "none";
		});
		endOnDate.addEventListener("change", function() {
			if (this.checked) endDateInput.style.display = "";
		});
	}

	// Append day-of-week name to "Which week" dropdown options
	var dayNames = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];
	var baseLabels = {};
	var womSelect = document.getElementById("evt_recurrence_week_of_month");
	var dateInput = document.getElementById("evt_start_time_dateinput");

	if (womSelect && dateInput) {
		for (var i = 0; i < womSelect.options.length; i++) {
			baseLabels[i] = womSelect.options[i].text;
		}

		function updateWomLabels() {
			var val = dateInput.value;
			if (!val) {
				for (var i = 0; i < womSelect.options.length; i++) {
					womSelect.options[i].text = baseLabels[i];
				}
				return;
			}
			var parts = val.split("-");
			var d = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
			var dow = dayNames[d.getDay()];
			for (var i = 0; i < womSelect.options.length; i++) {
				if (womSelect.options[i].value === "") {
					womSelect.options[i].text = baseLabels[i];
				} else {
					womSelect.options[i].text = baseLabels[i] + " " + dow;
				}
			}
		}

		dateInput.addEventListener("change", updateWomLabels);
		updateWomLabels();
	}
});
