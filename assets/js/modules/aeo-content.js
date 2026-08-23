        jQuery(function($){
            $("#pylon_aeo_answer").on("input", function() {
                var words = $.trim($(this).val()).split(/\s+/).filter(function(w){return w.length > 0}).length;
                var el = $("#pylon-aeo-wordcount");
                el.text(words + " words");
                el.css("background", words >= 50 && words <= 160 ? "#dcfce7" : (words > 0 ? "#fef3c7" : "#f1f5f9"));
                el.css("color", words >= 50 && words <= 160 ? "#166534" : (words > 0 ? "#92400e" : "#475569"));
            }).trigger("input");
        });
