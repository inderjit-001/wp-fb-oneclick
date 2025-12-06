jQuery(function($){
    $('#wpfboc-post-btn').on('click', function(e){
        e.preventDefault();
        var $btn = $(this);
        var post_id = $('#wpfboc-post-id').val();
        if (!post_id) {
            $('#wpfboc-result').text('Post ID not found');
            return;
        }
        $btn.addClass('loading').prop('disabled', true).text('Posting…');
        $('#wpfboc-result').text('');

        $.ajax({
            url: wpfboc.ajax_url,
            method: 'POST',
            data: {
                action: 'wpfboc_post_to_facebook',
                nonce: wpfboc.nonce,
                post_id: post_id
            },
            success: function(res){
                if (res.success) {
                    $('#wpfboc-result').css('color','#0a0').text(res.data);
                } else {
                    $('#wpfboc-result').css('color','#a00').text(res.data || res);
                }
            },
            error: function(xhr){
                $('#wpfboc-result').css('color','#a00').text('AJAX error: ' + xhr.statusText);
            },
            complete: function(){
                $btn.removeClass('loading').prop('disabled', false).text('Post to Facebook');
            }
        });
    });
});
