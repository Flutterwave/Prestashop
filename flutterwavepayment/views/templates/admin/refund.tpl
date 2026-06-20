<div class="panel">
    <h3>Flutterwave Refund</h3>

    <form method="post">

        <div class="form-group">
            <label>Order ID</label>

            <input
                type="number"
                name="id_order"
                class="form-control"
                required
            >
        </div>

        <div class="form-group">
            <label>Refund Amount</label>

            <input
                type="number"
                step="0.01"
                name="amount"
                class="form-control"
                required
            >
        </div>

        <button
            type="submit"
            name="submitFlutterwaveRefund"
            class="btn btn-primary"
        >
            Refund via Flutterwave
        </button>

    </form>
</div>