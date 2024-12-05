jQuery(document).ready(function ($) {
    $('.wholesaler-toggle').on('change', function () {
        if (!confirm("Are you sure you want to change this user's role?")) {
            // Якщо користувач натисне "Cancel", повернемо чекбокс до попереднього стану
            $(this).prop('checked', !$(this).is(':checked'));
            return;
        }

        const userId = $(this).data('user-id');
        const isChecked = $(this).is(':checked');

        $.ajax({
            url: wholesalerUsersAjax.ajax_url,
            type: 'POST',
            data: {
                action: 'toggle_wholesaler_role_user',
                nonce: wholesalerUsersAjax.nonce,
                user_id: userId,
                is_checked: isChecked
            },
            success: function (response) {
                if (!response.success) {
                    alert("Unsuccessful user role update.");
                } else {
                    alert("User role updated successfully.");
                }
                location.reload();
            },
            error: function () {
                alert("Error updating user role.");
            }
        });
    });
});
