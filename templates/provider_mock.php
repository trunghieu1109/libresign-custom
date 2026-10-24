<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
?>
<div class="guest-box">
	<h2>Xác nhận cấp chứng thư số</h2>
	<p>Trang thử nghiệm LibreSign</p>
	<?php if ($_['person']): ?>
		<p><strong><?php p($_['person']['name']); ?></strong></p>
		<p>CCCD: <?php p($_['person']['personalId']); ?></p>
		<?php if (in_array($_['session']['otpChannel'] ?? '', ['email', 'email_sms'], true)): ?>
			<p>Email nhận mã: <?php p($_['person']['email']); ?></p>
		<?php endif; ?>
		<?php if (in_array($_['session']['otpChannel'] ?? '', ['sms', 'email_sms'], true)): ?>
			<p>Số điện thoại nhận mã: <?php p($_['person']['phone']); ?></p>
		<?php endif; ?>
	<?php endif; ?>
	<?php if ($_['error']): ?><p role="alert"><?php p($_['error']); ?></p><?php endif; ?>
	<?php if ($_['notice']): ?><p role="status"><?php p($_['notice']); ?></p><?php endif; ?>
	<?php if (($_['session']['status'] ?? '') === 'ACTIVE'): ?>
		<p role="status">Đã hoàn tất cấp chứng thư số. Bạn có thể đóng trang này.</p>
	<?php elseif (($_['session']['status'] ?? '') === 'PENDING' && in_array($_['session']['step'], ['AWAITING_CONFIRMATION', 'VERIFIED'], true)): ?>
		<p>Mã có hiệu lực tối đa 5 phút.<?php if (($_['session']['otpChannel'] ?? '') === 'email_sms'): ?> Cùng một mã được gửi qua email và SMS; chỉ cần nhập một lần.<?php endif; ?></p>
		<form method="post" action="">
			<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">
			<input type="hidden" name="token" value="<?php p($_['token']); ?>">
			<input type="hidden" name="action" value="confirm">
			<label for="otpCode">Mã OTP gồm 6 số</label>
			<input id="otpCode" name="otpCode" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required>
			<p><label><input name="consent" type="checkbox" value="yes" required> Tôi đồng ý cấp chứng thư số với thông tin đăng ký.</label></p>
			<button type="submit" class="primary">Xác nhận cấp chứng thư</button>
		</form>
		<form method="post" action="">
			<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">
			<input type="hidden" name="token" value="<?php p($_['token']); ?>">
			<input type="hidden" name="action" value="resend">
			<button type="submit">Gửi lại OTP</button>
		</form>
	<?php elseif (($_['session']['status'] ?? '') === 'PENDING'): ?>
		<p>Phiên đang chờ hồ sơ hoặc kết quả xác minh từ bên tích hợp. Tải lại trang sau khi hoàn tất bước này.</p>
	<?php elseif ($_['session']): ?>
		<p>Phiên hiện không thể tiếp tục. Vui lòng yêu cầu bên tích hợp kiểm tra hoặc tạo phiên mới.</p>
	<?php endif; ?>
</div>
