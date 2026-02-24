document.addEventListener("DOMContentLoaded", () => {
    const form = document.getElementById("loginForm");
    const msg = document.getElementById("loginMsg");

form.addEventListener("submit", async (e) => {
    e.preventDefault();
    msg.textContent = "";

    const email = document.getElementById("email").value.trim();
    const password = document.getElementById("password").value;

    try {
        const res = await API.login({ email, password }); // <-- MUST call API
        if (!res.success) throw new Error(res.message || "Login failed");

      // quick verify (optional but very helpful)
        const me = await API.me();
        console.log("ME:", me);

        window.location.href = "/venture-magnate/frontend/js/Portfolio.html";
    } catch (err) {
    msg.textContent = err.message;
    }
});
});


