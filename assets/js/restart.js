if($("#idTime").length && typeof moment !== "undefined") {
    let time = parseInt($("#idTime").data("time"), 10);
    const timezone = $("#idTime").data("zone");
    setInterval(() => $("#idTime").text(moment.unix(time++).tz(timezone).format('HH:mm:ss z')), 1000);
}

$("#selectall").on("click", function() {
    $("#xtnlist option").attr("selected", true);
});

$("input[name=enable_schedule]").on("change", function() {
    const val = Boolean(parseInt(this.value));
    $(".scheduler").not("#schedexpression").prop("disabled", !val);
    if (!val) {
        $("#schedexpression").prop("disabled", $("enable-schedexpression").prop("checked"));
    }
    $("#schedtime:enabled").focus();
});

$("#schedmonth").on("change", function() {
    const sel = $("#schedmonth");
    const months = [0, 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    const days = months[sel.val()];
    $("#schedday option").each(function(i, el) {
        el = $(el);
        el.prop("disabled", (el.val() > days));
    });
    if ($("#schedday option:selected").prop("disabled")) {
        $("#schedday").val(days).prop("selectedIndex", days);
    }
});

$("#enable-schedexpression").on("change", function() {
    $(".scheduler").not("#schedexpression, #enable-schedexpression").prop("disabled", this.checked);
    $("#schedexpression").prop("disabled", !this.checked);
})

$("#pending_restart_grid").on("load-success.bs.table", function(e) {
    $("a.deleter").on("click", function(e) {
        e.preventDefault();
        if (confirm(_("Are you sure you wish to delete this job?"))) {
            $.get(this.href, function () {
                $("#pending_restart_grid").bootstrapTable("refresh", {silent: true});
            });
        }
    });
});

var Restart = {
    actionLinkFormatter: function (value, row, index) {
        return $("<a>")
            .attr("href", "ajax.php?module=restart&command=deleteJob&itemid=" + encodeURIComponent(row.jobname))
            .addClass("deleter")
            .append($("<i>").addClass("fa").addClass("fa-trash"))
            .prop("outerHTML");
    }
};
